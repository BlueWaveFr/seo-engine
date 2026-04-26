<?php

namespace SeoExpert\Engine\Service\Agent;

use SeoExpert\Engine\Entity\AgentTask;
use SeoExpert\Engine\Entity\ApiKey;
use SeoExpert\Engine\Entity\ClientContext;
use SeoExpert\Engine\Entity\Content;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\User;
use SeoExpert\Engine\Service\AI\ClaudeService;
use SeoExpert\Engine\Service\Audit\SiteAuditService;
use SeoExpert\Engine\Service\Crawler\KeywordAnalyzerService;
use SeoExpert\Engine\Service\Crawler\SemanticCrawlerService;
use SeoExpert\Engine\Service\RankTracking\RankTrackingService;
use SeoExpert\Engine\Service\WordPress\WordPressService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AgentOrchestrator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-sonnet-4-5';
    private const MAX_TOKENS = 8192;

    private ?string $effectiveApiKey = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ClientContextService $clientContextService,
        private readonly AgentLogger $agentLogger,
        private readonly SiteAuditService $siteAuditService,
        private readonly KeywordAnalyzerService $keywordAnalyzerService,
        private readonly RankTrackingService $rankTrackingService,
        private readonly WordPressService $wordPressService,
        private readonly ClaudeService $claudeService,
        private readonly SemanticCrawlerService $semanticCrawlerService,
        private readonly AgentNotificationService $notificationService,
        private readonly string $apiKey = '',
    ) {
        $this->initializeApiKey();
    }

    /**
     * When true, skips sending the approval email for the next planTask() call.
     * Used by the CLI test harness via `--no-email`.
     */
    private bool $suppressNotifications = false;

    public function setSuppressNotifications(bool $suppress): void
    {
        $this->suppressNotifications = $suppress;
    }

    // -----------------------------------------------------------------------
    //  1. Plan — Ask Claude to propose a set of actions for a task type
    // -----------------------------------------------------------------------

    /**
     * Create an agent task: load context, call Claude with tool_use to produce a plan,
     * then persist the task as "awaiting_approval".
     */
    public function planTask(
        Project $project,
        User $user,
        string $taskType,
        ?array $additionalInput = null,
    ): AgentTask {
        $context = $this->clientContextService->getOrCreateContext($project);

        $task = new AgentTask();
        $task->setProject($project);
        $task->setUser($user);
        $task->setTaskType($taskType);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        try {
            $systemPrompt = $this->buildSystemPrompt($context, $taskType);
            $userMessage = $this->buildUserMessage($taskType, $additionalInput);

            $response = $this->callClaudeWithTools($systemPrompt, $userMessage);

            $plan = $this->extractPlan($response);
            $reasoning = $this->extractReasoning($response);
            $tokenUsage = $this->extractTokenUsage($response);

            $task->setPlan($plan);
            $task->setLlmReasoning($reasoning);
            $task->setLlmModel(self::MODEL);
            $task->setTokenUsage($tokenUsage);
            $task->markAsAwaitingApproval();
        } catch (\Throwable $e) {
            $this->logger->error('Agent plan generation failed', [
                'task_id' => $task->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            $task->markAsFailed(['error' => $e->getMessage()]);
        }

        $this->entityManager->flush();

        // Fire approval-required email only when the plan was persisted as awaiting_approval
        // (skips failed plans). Graceful — failures are logged inside the service.
        if (!$this->suppressNotifications && $task->getStatus() === AgentTask::STATUS_AWAITING_APPROVAL) {
            $this->notificationService->sendApprovalRequiredEmail($task);
        }

        return $task;
    }

    // -----------------------------------------------------------------------
    //  2. Execute — Run every action from the approved plan
    // -----------------------------------------------------------------------

    /**
     * Execute an approved task: iterate over each action in the plan,
     * call the mapped internal service, log results.
     */
    public function executeTask(AgentTask $task): AgentTask
    {
        $startStatus = $task->getStatus();
        if ($startStatus !== AgentTask::STATUS_APPROVED
            && $startStatus !== AgentTask::STATUS_EXECUTING
        ) {
            throw new \LogicException(sprintf(
                'Cannot execute task %s — status is "%s", expected "approved" or "executing".',
                $task->getId()->toRfc4122(),
                $startStatus,
            ));
        }

        // Flip to "executing" as soon as we start so the UI shows "En cours" immediately
        // (idempotent: skip if a previous run already set it).
        if ($startStatus !== AgentTask::STATUS_EXECUTING) {
            $task->markAsExecuting();
            $this->entityManager->flush();
        }

        $project = $task->getProject();
        $context = $this->clientContextService->getOrCreateContext($project);
        $plan = $task->getPlan() ?? [];
        $results = [];
        $hasError = false;

        foreach ($plan as $index => $action) {
            $toolName = $action['tool'] ?? $action['name'] ?? null;
            $toolParams = $action['parameters'] ?? $action['input'] ?? [];

            if ($toolName === null) {
                continue;
            }

            $startTime = microtime(true);

            try {
                $toolResult = $this->executeTool($toolName, $toolParams, $project);

                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                $this->agentLogger->logToolExecution(
                    task: $task,
                    project: $project,
                    action: $toolName,
                    params: $toolParams,
                    result: $toolResult,
                    status: 'success',
                    duration: $durationMs,
                );

                // Update context with a summary of the result
                $this->clientContextService->recordAction($context, [
                    'tool' => $toolName,
                    'result_summary' => $this->summarizeResult($toolResult),
                ]);

                $results[] = [
                    'step' => $index + 1,
                    'tool' => $toolName,
                    'status' => 'success',
                    'result' => $toolResult,
                    'duration_ms' => $durationMs,
                ];
            } catch (\Throwable $e) {
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $hasError = true;

                $this->agentLogger->logToolError(
                    task: $task,
                    project: $project,
                    action: $toolName,
                    params: $toolParams,
                    errorMessage: $e->getMessage(),
                    duration: $durationMs,
                );

                $results[] = [
                    'step' => $index + 1,
                    'tool' => $toolName,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'duration_ms' => $durationMs,
                ];

                $this->logger->error('Agent tool execution failed', [
                    'task_id' => $task->getId()->toRfc4122(),
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);

                // Continue with remaining actions — a single failure should not halt the plan.
            }
        }

        if ($hasError) {
            $task->markAsFailed($results);
        } else {
            $task->markAsCompleted($results);
        }

        // Record the decision in context
        $this->clientContextService->recordDecision(
            $context,
            sprintf('Executed task "%s" — %d actions, %s',
                $task->getTaskType(),
                count($plan),
                $hasError ? 'completed with errors' : 'all succeeded',
            ),
            $task->getLlmReasoning(),
        );

        $this->entityManager->flush();

        return $task;
    }

    // -----------------------------------------------------------------------
    //  Tool execution dispatcher
    // -----------------------------------------------------------------------

    /**
     * Route a tool call to the corresponding internal WaveRank service.
     *
     * @return array The result payload from the tool
     */
    private function executeTool(string $toolName, array $params, Project $project): array
    {
        return match ($toolName) {
            'run_technical_audit'      => $this->toolRunTechnicalAudit($project, $params),
            'get_keyword_opportunities' => $this->toolGetKeywordOpportunities($project, $params),
            'generate_content_brief'   => $this->toolGenerateContentBrief($project, $params),
            'check_position_changes'   => $this->toolCheckPositionChanges($project, $params),
            'run_semantic_audit'       => $this->toolRunSemanticAudit($project, $params),
            'publish_to_wordpress'     => $this->toolPublishToWordPress($project, $params),
            default => throw new \InvalidArgumentException(sprintf('Unknown agent tool: "%s"', $toolName)),
        };
    }

    // -- run_technical_audit ------------------------------------------------

    private function toolRunTechnicalAudit(Project $project, array $params): array
    {
        $url = $params['url'] ?? null;

        $audit = $this->siteAuditService->createAudit($project, $url);
        $audit = $this->siteAuditService->runAudit($audit);

        // Update baseline metrics in context
        $context = $this->clientContextService->getOrCreateContext($project);
        $this->clientContextService->updateBaselineMetrics($context, [
            'overall_score' => $audit->getOverallScore(),
            'technical_score' => $audit->getTechnicalScore(),
            'seo_score' => $audit->getSeoScore(),
            'performance_mobile' => $audit->getPerformanceScoreMobile(),
            'critical_issues' => $audit->getCriticalIssuesCount(),
            'warning_issues' => $audit->getWarningIssuesCount(),
        ]);

        return [
            'audit_id' => $audit->getId(),
            'overall_score' => $audit->getOverallScore(),
            'technical_score' => $audit->getTechnicalScore(),
            'seo_score' => $audit->getSeoScore(),
            'performance_mobile' => $audit->getPerformanceScoreMobile(),
            'critical_issues' => $audit->getCriticalIssuesCount(),
            'warning_issues' => $audit->getWarningIssuesCount(),
            'status' => $audit->getStatus(),
        ];
    }

    // -- get_keyword_opportunities ------------------------------------------

    private function toolGetKeywordOpportunities(Project $project, array $params): array
    {
        $crawledData = [
            'domain' => $this->extractDomain($project->getWebsiteUrl()),
            'statistics' => ['totalPages' => 0, 'totalWords' => 0],
        ];

        $projectContext = [
            'domain' => $this->extractDomain($project->getWebsiteUrl()),
            'name' => $project->getName(),
        ];

        if (!empty($params['focus_topic'])) {
            $projectContext['focus_topic'] = $params['focus_topic'];
        }

        $analysis = $this->keywordAnalyzerService->analyzeWebsiteContent($crawledData, $projectContext);

        return [
            'keywords' => $analysis['keywords'] ?? [],
            'clusters' => $analysis['clusters'] ?? [],
            'opportunities' => $analysis['opportunities'] ?? [],
        ];
    }

    // -- generate_content_brief ---------------------------------------------

    private function toolGenerateContentBrief(Project $project, array $params): array
    {
        $keyword = $params['keyword'] ?? '';
        $contentType = $params['content_type'] ?? 'article';

        if ($keyword === '') {
            throw new \InvalidArgumentException('Parameter "keyword" is required for generate_content_brief.');
        }

        $ideas = $this->claudeService->generateKeywordContentIdeas($project, $keyword, [
            'content_type' => $contentType,
        ]);

        return [
            'keyword' => $keyword,
            'content_type' => $contentType,
            'ideas' => $ideas,
        ];
    }

    // -- run_semantic_audit -------------------------------------------------

    private function toolRunSemanticAudit(Project $project, array $params): array
    {
        $websiteUrl = $project->getWebsiteUrl();
        if (!$websiteUrl) {
            throw new \RuntimeException('Project has no website URL configured.');
        }

        $forceRecrawl = (bool) ($params['force_recrawl'] ?? false);
        $existingData = $project->getCrawledData() ?? [];
        $lastCrawledAt = $project->getLastCrawledAt();

        $isFresh = false;
        if (!$forceRecrawl && $lastCrawledAt !== null && isset($existingData['crawl'])) {
            $ageInDays = ((new \DateTimeImmutable())->getTimestamp() - $lastCrawledAt->getTimestamp()) / 86400;
            $isFresh = $ageInDays < 7;
        }

        $crawlTriggered = false;
        if ($isFresh) {
            $crawledData = $existingData['crawl'];
        } else {
            $crawledData = $this->semanticCrawlerService->crawlWebsite($websiteUrl, 30);
            $crawlTriggered = true;
        }

        $projectContext = [
            'industry' => $project->getIndustry(),
            'description' => $project->getDescription(),
            'targetCountry' => $project->getTargetCountry(),
            'targetLanguage' => $project->getTargetLanguage(),
        ];

        $analysis = $this->keywordAnalyzerService->analyzeWebsiteContent($crawledData, $projectContext);

        // Persist crawl + analysis on the project (mirrors SemanticCrawlerController::triggerCrawlSync)
        if ($crawlTriggered) {
            $project->setCrawledData([
                'crawl' => $crawledData,
                'analysis' => $analysis,
                'analyzedAt' => (new \DateTimeImmutable())->format('c'),
            ]);
        } else {
            $existingData['analysis'] = $analysis;
            $existingData['analyzedAt'] = (new \DateTimeImmutable())->format('c');
            $project->setCrawledData($existingData);
        }

        // Update baseline metrics in context with semantic scores
        $semanticScore = $analysis['semanticAnalysis']['coherenceScore'] ?? null;
        $overallSemanticScore = $analysis['summary']['overallScore'] ?? null;
        $context = $this->clientContextService->getOrCreateContext($project);
        $this->clientContextService->updateBaselineMetrics($context, [
            'semantic_coherence_score' => $semanticScore,
            'semantic_overall_score' => $overallSemanticScore,
            'topical_authority' => $analysis['semanticAnalysis']['topicalAuthority'] ?? null,
            'content_depth_score' => $analysis['semanticAnalysis']['contentDepthScore'] ?? null,
            'internal_linking_score' => $analysis['semanticAnalysis']['internalLinkingScore'] ?? null,
        ]);

        // Build semantic cocoon coverage summary
        $cocoon = $analysis['semanticCocoon'] ?? [];
        $pillars = $cocoon['pillars'] ?? [];
        $coverageScores = array_column($pillars, 'coverageScore');
        $semanticCocoon = [
            'pillars_count' => count($pillars),
            'average_coverage' => $coverageScores !== [] ? (int) round(array_sum($coverageScores) / count($coverageScores)) : 0,
            'pillars' => array_map(static fn($p) => [
                'theme' => $p['theme'] ?? null,
                'coverage_score' => $p['coverageScore'] ?? null,
                'interlinking_score' => $p['interlinkingScore'] ?? null,
                'existing_content_count' => count($p['existingContent'] ?? []),
                'missing_content_count' => count($p['missingContent'] ?? []),
            ], $pillars),
        ];

        $topClusters = array_map(static fn($t) => [
            'name' => $t['name'] ?? null,
            'coverage' => $t['coverage'] ?? null,
            'is_pillar' => $t['isPillar'] ?? false,
        ], array_slice($analysis['themes'] ?? [], 0, 5));

        $contentGaps = array_slice($analysis['semanticAnalysis']['contentGaps'] ?? [], 0, 10);

        return [
            'crawl_triggered' => $crawlTriggered,
            'crawled_pages' => $crawledData['statistics']['totalPages'] ?? 0,
            'semantic_cocoon' => $semanticCocoon,
            'top_clusters' => $topClusters,
            'content_gaps' => $contentGaps,
            'recommendations_count' => count($analysis['recommendations'] ?? []),
            'overall_score' => $overallSemanticScore,
            'coherence_score' => $semanticScore,
        ];
    }

    // -- check_position_changes ---------------------------------------------

    private function toolCheckPositionChanges(Project $project, array $params): array
    {
        $summary = $this->rankTrackingService->getProjectSummary($project);

        return [
            'total_keywords' => $summary['total_keywords'] ?? 0,
            'top_3' => $summary['top_3'] ?? 0,
            'top_10' => $summary['top_10'] ?? 0,
            'top_20' => $summary['top_20'] ?? 0,
            'improved' => $summary['improved'] ?? 0,
            'declined' => $summary['declined'] ?? 0,
            'stable' => $summary['stable'] ?? 0,
            'average_position' => $summary['average_position'],
            'visibility_score' => $summary['visibility_score'] ?? 0,
        ];
    }

    // -- publish_to_wordpress -----------------------------------------------

    private function toolPublishToWordPress(Project $project, array $params): array
    {
        $wpUrl = $project->getWordpressUrl();
        $wpUsername = $project->getWordpressUsername();
        $wpPassword = $project->getWordpressAppPassword();

        if (!$wpUrl || !$wpUsername || !$wpPassword) {
            throw new \RuntimeException('WordPress credentials are not configured for this project.');
        }

        $contentId = $params['content_id'] ?? null;
        if ($contentId === null) {
            throw new \InvalidArgumentException('Parameter "content_id" is required for publish_to_wordpress.');
        }

        $content = $this->entityManager->getRepository(Content::class)->find($contentId);
        if ($content === null) {
            throw new \RuntimeException(sprintf('Content entity "%s" not found.', $contentId));
        }

        $postType = $params['post_type'] ?? $project->getWordpressDefaultPostType() ?? 'posts';
        $status = $params['status'] ?? $project->getWordpressDefaultStatus() ?? 'draft';

        $result = $this->wordPressService->publishContent(
            siteUrl: $wpUrl,
            username: $wpUsername,
            applicationPassword: $wpPassword,
            content: $content,
            postType: $postType,
            status: $status,
        );

        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('WordPress publish failed: ' . ($result['error'] ?? 'unknown error'));
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    //  Claude API — tool_use call
    // -----------------------------------------------------------------------

    /**
     * Call Claude Messages API with tool definitions and return the raw decoded response.
     */
    private function callClaudeWithTools(string $systemPrompt, string $userMessage): array
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $systemPrompt,
            'tools' => $this->getToolDefinitions(),
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        $attempt = 0;
        $maxRetries = 3;
        $lastException = null;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                $response = $this->httpClient->request('POST', self::API_URL, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-api-key' => $this->getApiKey(),
                        'anthropic-version' => '2023-06-01',
                    ],
                    'timeout' => 120,
                    'json' => $payload,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode < 200 || $statusCode >= 300) {
                    // Fetch the raw body even on error status so we can surface Anthropic's error details.
                    $body = $response->getContent(false);

                    $this->logger->error('Claude API returned non-2xx status', [
                        'status_code' => $statusCode,
                        'url' => self::API_URL,
                        'response_body' => $body,
                        'attempt' => $attempt,
                    ]);

                    throw new \RuntimeException(sprintf(
                        'Claude API HTTP %d at %s — response body: %s',
                        $statusCode,
                        self::API_URL,
                        $body,
                    ));
                }

                return $response->toArray();
            } catch (\Throwable $e) {
                $lastException = $e;
                $errorMsg = $e->getMessage();

                $isRetryable = str_contains($errorMsg, '429')
                    || str_contains($errorMsg, '529')
                    || str_contains($errorMsg, '500')
                    || str_contains($errorMsg, '502')
                    || str_contains($errorMsg, '503');

                if ($isRetryable && $attempt < $maxRetries) {
                    $wait = (int) pow(2, $attempt);
                    $this->logger->warning(sprintf(
                        'Claude API error (attempt %d/%d), retrying in %ds: %s',
                        $attempt, $maxRetries, $wait, $errorMsg,
                    ));
                    sleep($wait);
                    continue;
                }

                break;
            }
        }

        throw new \RuntimeException(
            'Claude API call failed after ' . $attempt . ' attempts: ' . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException,
        );
    }

    // -----------------------------------------------------------------------
    //  Tool definitions (Claude tool_use format)
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    private function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'run_technical_audit',
                'description' => 'Run a full technical SEO audit on the project website. Returns scores for technical SEO, performance, security, and a list of critical issues.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Optional URL to audit. Defaults to the project website URL.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_keyword_opportunities',
                'description' => 'Analyze the project website to identify keyword opportunities, clusters, and content gaps. Use this to discover new ranking opportunities.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'focus_topic' => [
                            'type' => 'string',
                            'description' => 'Optional topic to focus the keyword analysis on.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'generate_content_brief',
                'description' => 'Generate an AI-powered content brief for a given keyword. Returns title ideas, outlines, and SEO recommendations for content creation.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => [
                            'type' => 'string',
                            'description' => 'The primary keyword to create content around.',
                        ],
                        'content_type' => [
                            'type' => 'string',
                            'description' => 'Type of content to generate (article, guide, pillar_page, faq, tutorial, etc.). Defaults to "article".',
                            'enum' => ['article', 'blog_post', 'guide', 'comprehensive_guide', 'pillar_page', 'landing_page', 'faq', 'tutorial', 'case_study', 'comparison'],
                        ],
                    ],
                    'required' => ['keyword'],
                ],
            ],
            [
                'name' => 'check_position_changes',
                'description' => 'Check the current rank tracking summary for the project: number of tracked keywords, position distribution (top 3/10/20), improvements, declines, average position, and visibility score.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'run_semantic_audit',
                'description' => 'Run a comprehensive semantic audit: crawl the site (if needed) and analyze semantic coverage, topic clusters, and content gaps. Use this when the user wants a deep content/semantic analysis rather than technical SEO.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'force_recrawl' => [
                            'type' => 'boolean',
                            'description' => 'Force a fresh crawl even if recent data exists. Defaults to false.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'publish_to_wordpress',
                'description' => 'Publish an existing content piece to the project WordPress site. Requires the project to have WordPress credentials configured.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content_id' => [
                            'type' => 'string',
                            'description' => 'The UUID of the Content entity to publish.',
                        ],
                        'post_type' => [
                            'type' => 'string',
                            'description' => 'WordPress post type ("posts" or "pages"). Defaults to project setting.',
                        ],
                        'status' => [
                            'type' => 'string',
                            'description' => 'WordPress post status ("draft", "publish", "pending"). Defaults to project setting or "draft".',
                            'enum' => ['draft', 'publish', 'pending', 'private'],
                        ],
                    ],
                    'required' => ['content_id'],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    //  Prompt builders
    // -----------------------------------------------------------------------

    private function buildSystemPrompt(ClientContext $context, string $taskType): string
    {
        $contextPrompt = $this->clientContextService->buildContextPrompt($context);

        return <<<PROMPT
Tu es **l'Agent IA WaveRank**, un expert SEO senior autonome intégré à la plateforme WaveRank.io.
Ton rôle : analyser la situation SEO d'un projet et proposer un plan d'action concret et priorisé à l'aide des outils disponibles.

Tu DOIS répondre avec un ou plusieurs appels `tool_use` qui forment ensemble un plan SEO cohérent. Chaque appel = une étape du plan.

## Format de réponse

Ton texte d'introduction (reasoning) **doit être en français**, concis, et utiliser du **markdown** pour la lisibilité :

- **Titres courts** (`##` max) pour structurer : *Analyse*, *Plan d'action*, *Pourquoi cette approche*
- **Listes à puces** pour les points clés (critical issues, scores, recommandations)
- **Gras** (`**texte**`) pour les chiffres ou mots-clés importants
- Ton **professionnel mais accessible** — tu parles à un responsable SEO / dirigeant de PME
- Termine toujours ton reasoning par une phrase qui **rappelle la valeur de l'Agent IA WaveRank** :
  *exemples : "L'Agent IA WaveRank te fait gagner des heures d'analyse chaque semaine."*,
  *"Avec WaveRank Agent, ton SEO avance pendant que tu dors."*,
  *"Tu valides, WaveRank Agent exécute — c'est toi qui gardes le contrôle."*
  (varie les formulations, ne les répète pas à l'identique)

## Méthode

1. Analyse le contexte du projet et l'historique récent
2. Identifie les **actions à plus fort impact** pour le type de tâche demandé
3. Appelle les outils pertinents avec les bons paramètres
4. Explique brièvement ta logique avant les appels d'outils

## Règles

- Privilégie toujours les décisions basées sur les données (pas d'hypothèses).
- Si le projet n'a jamais été audité, commence par un audit technique.
- N'appelle `publish_to_wordpress` que si c'est explicitement demandé et qu'un `content_id` est connu.
- Propose uniquement des actions pertinentes pour la tâche demandée : **"{$taskType}"**.
- Pour `semantic_audit` (analyse de contenu en profondeur, cocon sémantique, autorité thématique), privilégie `run_semantic_audit` plutôt que `get_keyword_opportunities`. Ce dernier reste utile pour un check léger au niveau mots-clés.

{$contextPrompt}
PROMPT;
    }

    private function buildUserMessage(string $taskType, ?array $additionalInput): string
    {
        $message = match ($taskType) {
            'full_analysis'     => "Réalise une analyse SEO complète de ce projet : audit technique, vérification des positions, et identification des opportunités de mots-clés.",
            'content_strategy'  => "Propose une stratégie de contenu : identifie les opportunités de mots-clés et génère des briefs de contenu pour les plus prometteurs.",
            'technical_audit'   => "Lance un audit technique SEO sur ce projet et rapporte les points à corriger.",
            'weekly_audit'      => "Réalise l'audit hebdomadaire de ce projet : vérifie la santé technique, les variations de positions, et les opportunités de la semaine.",
            'daily_position_check' => "Vérifie les positions du jour : repère les chutes significatives et les mouvements importants.",
            'content_gap_alert' => "Identifie les gaps de contenu : quels sujets manque-t-il à ce projet pour gagner du trafic ?",
            'monthly_report'    => "Génère le rapport mensuel : santé technique, évolution des positions, opportunités de croissance, prochaines priorités.",
            'rank_check'        => "Vérifie les positions actuelles et identifie les changements significatifs.",
            'content_publish'   => "Publie le contenu spécifié sur WordPress.",
            'semantic_audit'    => "Réalise un audit sémantique approfondi : analyse les clusters thématiques, la couverture du cocon sémantique, et les gaps de contenu.",
            default             => sprintf("Exécute la tâche SEO suivante : %s", $taskType),
        };

        if (!empty($additionalInput)) {
            $message .= "\n\nContexte additionnel :\n" . json_encode($additionalInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return $message;
    }

    // -----------------------------------------------------------------------
    //  Response parsing
    // -----------------------------------------------------------------------

    /**
     * Extract the list of planned tool calls from Claude's response.
     *
     * @return array<int, array{tool: string, parameters: array}>
     */
    private function extractPlan(array $response): array
    {
        $plan = [];

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $plan[] = [
                    'tool' => $block['name'],
                    'parameters' => $block['input'] ?? [],
                ];
            }
        }

        return $plan;
    }

    /**
     * Extract the text reasoning blocks from Claude's response.
     */
    private function extractReasoning(array $response): ?string
    {
        $texts = [];

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $texts[] = $block['text'];
            }
        }

        return $texts !== [] ? implode("\n\n", $texts) : null;
    }

    /**
     * Extract token usage from Claude's response.
     */
    private function extractTokenUsage(array $response): ?array
    {
        $usage = $response['usage'] ?? null;
        if ($usage === null) {
            return null;
        }

        return [
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ];
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    private function initializeApiKey(): void
    {
        $apiKeyEntity = $this->entityManager->getRepository(ApiKey::class)
            ->findOneBy(['provider' => ApiKey::PROVIDER_ANTHROPIC, 'isActive' => true]);

        if ($apiKeyEntity && $apiKeyEntity->getApiKey()) {
            $this->effectiveApiKey = $apiKeyEntity->getApiKey();
        } elseif (!empty($this->apiKey)) {
            $this->effectiveApiKey = $this->apiKey;
        }
    }

    private function getApiKey(): string
    {
        if (!$this->effectiveApiKey) {
            throw new \RuntimeException('Anthropic API key not configured. Please add it in Admin > API Keys.');
        }

        return $this->effectiveApiKey;
    }

    private function extractDomain(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: $url;
    }

    /**
     * Produce a short one-line summary of a tool result for context storage.
     */
    private function summarizeResult(array $result): string
    {
        // Technical audit
        if (isset($result['overall_score'])) {
            return sprintf(
                'Score: %s/100, %d critical issues, %d warnings',
                $result['overall_score'] ?? 'N/A',
                $result['critical_issues'] ?? 0,
                $result['warning_issues'] ?? 0,
            );
        }

        // Rank tracking
        if (isset($result['visibility_score'])) {
            return sprintf(
                '%d keywords tracked, avg pos %.1f, visibility %.1f%%',
                $result['total_keywords'] ?? 0,
                $result['average_position'] ?? 0,
                $result['visibility_score'] ?? 0,
            );
        }

        // WordPress publish
        if (isset($result['post'])) {
            return sprintf(
                'Published post #%s (%s)',
                $result['post']['id'] ?? '?',
                $result['post']['status'] ?? 'draft',
            );
        }

        // Content brief
        if (isset($result['keyword'])) {
            return sprintf('Brief generated for keyword "%s"', $result['keyword']);
        }

        // Keyword opportunities
        if (isset($result['opportunities'])) {
            return sprintf('%d opportunities found', count($result['opportunities']));
        }

        // Semantic audit
        if (isset($result['semantic_cocoon'])) {
            return sprintf(
                'Semantic audit: %d pages, %d pillars (%d%% coverage), %d content gaps',
                $result['crawled_pages'] ?? 0,
                $result['semantic_cocoon']['pillars_count'] ?? 0,
                $result['semantic_cocoon']['average_coverage'] ?? 0,
                count($result['content_gaps'] ?? []),
            );
        }

        return 'Completed';
    }
}
