<?php

namespace SeoExpert\Engine\Service\AI;

use SeoExpert\Engine\Entity\ApiKey;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Service\ApiUsageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class ClaudeService implements AIProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 4096;
    private const MAX_TOKENS_MEDIUM = 8192; // For medium content (tutorials, FAQs, etc.)
    private const MAX_TOKENS_LONG = 16000; // For long content (pillar pages, etc.)
    private const MAX_TOKENS_GUIDE = 32000; // Extended tokens for comprehensive guides (3500+ words)
    private const PROVIDER_NAME = 'anthropic';

    // Content type configurations with word counts and token limits
    private const CONTENT_TYPE_CONFIG = [
        'article' => ['min_words' => 800, 'max_words' => 1500, 'tokens' => self::MAX_TOKENS],
        'blog_post' => ['min_words' => 600, 'max_words' => 1200, 'tokens' => self::MAX_TOKENS],
        'guide' => ['min_words' => 1500, 'max_words' => 2500, 'tokens' => self::MAX_TOKENS_MEDIUM],
        'comprehensive_guide' => ['min_words' => 3500, 'max_words' => 6000, 'tokens' => self::MAX_TOKENS_GUIDE],
        'pillar_page' => ['min_words' => 3000, 'max_words' => 5000, 'tokens' => self::MAX_TOKENS_LONG],
        'landing_page' => ['min_words' => 500, 'max_words' => 1000, 'tokens' => self::MAX_TOKENS],
        'product_description' => ['min_words' => 300, 'max_words' => 600, 'tokens' => self::MAX_TOKENS],
        'faq' => ['min_words' => 800, 'max_words' => 1500, 'tokens' => self::MAX_TOKENS_MEDIUM],
        'tutorial' => ['min_words' => 1200, 'max_words' => 2500, 'tokens' => self::MAX_TOKENS_MEDIUM],
        'case_study' => ['min_words' => 1000, 'max_words' => 2000, 'tokens' => self::MAX_TOKENS_MEDIUM],
        'comparison' => ['min_words' => 1000, 'max_words' => 2000, 'tokens' => self::MAX_TOKENS_MEDIUM],
        'glossary' => ['min_words' => 500, 'max_words' => 1500, 'tokens' => self::MAX_TOKENS],
        'social_post' => ['min_words' => 50, 'max_words' => 300, 'tokens' => self::MAX_TOKENS],
        'newsletter' => ['min_words' => 400, 'max_words' => 800, 'tokens' => self::MAX_TOKENS],
        'page' => ['min_words' => 500, 'max_words' => 1000, 'tokens' => self::MAX_TOKENS],
    ];

    /**
     * Threshold month: from November onwards, propose next year in content titles.
     * Before November, use current year.
     */
    private const YEAR_THRESHOLD_MONTH = 11; // November

    private ?string $effectiveApiKey = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $apiKey = '',
        private readonly ?ApiUsageService $apiUsageService = null,
    ) {
        $this->initializeApiKey();
    }

    private function initializeApiKey(): void
    {
        // Try to get API key from database first
        $apiKeyEntity = $this->entityManager->getRepository(ApiKey::class)
            ->findOneBy(['provider' => ApiKey::PROVIDER_ANTHROPIC, 'isActive' => true]);

        if ($apiKeyEntity && $apiKeyEntity->getApiKey()) {
            $this->effectiveApiKey = $apiKeyEntity->getApiKey();
            $this->logger->info('Using Anthropic API key from database');
        } elseif (!empty($this->apiKey)) {
            $this->effectiveApiKey = $this->apiKey;
            $this->logger->info('Using Anthropic API key from environment');
        } else {
            $this->logger->warning('No Anthropic API key configured');
        }
    }

    private function getApiKey(): string
    {
        if (!$this->effectiveApiKey) {
            throw new \RuntimeException('Anthropic API key not configured. Please add it in Admin > API Keys.');
        }
        return $this->effectiveApiKey;
    }

    public function generateContentIdeas(Project $project, array $options = []): array
    {
        $count = $options['count'] ?? 10;
        $types = $options['types'] ?? ['article', 'blog_post', 'page', 'social_post'];

        $prompt = $this->buildContentIdeasPrompt($project, $count, $types);
        $response = $this->callApi($prompt);

        return $this->parseContentIdeasResponse($response);
    }

    public function generateContent(string $title, string $type, Project $project, array $options = []): array
    {
        // Get content type configuration
        $config = self::CONTENT_TYPE_CONFIG[$type] ?? self::CONTENT_TYPE_CONFIG['article'];
        $maxTokens = $config['tokens'];

        // Route to specialized prompt builder based on content type
        $prompt = match ($type) {
            'comprehensive_guide' => $this->buildGuideGenerationPrompt($title, $project, $options),
            'pillar_page' => $this->buildPillarPagePrompt($title, $project, $options),
            'landing_page' => $this->buildLandingPagePrompt($title, $project, $options),
            'product_description' => $this->buildProductDescriptionPrompt($title, $project, $options),
            'faq' => $this->buildFaqPrompt($title, $project, $options),
            'tutorial' => $this->buildTutorialPrompt($title, $project, $options),
            'case_study' => $this->buildCaseStudyPrompt($title, $project, $options),
            'comparison' => $this->buildComparisonPrompt($title, $project, $options),
            default => $this->buildContentGenerationPrompt($title, $type, $project, $options),
        };

        // Use appropriate token limit
        if ($maxTokens > self::MAX_TOKENS) {
            $response = $this->callApiWithTokens($prompt, null, 3, $maxTokens);
        } else {
            $response = $this->callApi($prompt);
        }

        return $this->parseContentResponse($response, $title);
    }

    private function parseContentResponse(string $response, string $fallbackTitle): array
    {
        $data = $this->parseJsonResponse($response);

        $contentHtml = $data['content_html'] ?? $data['content'] ?? null;

        // If JSON parsing failed entirely, use raw response as HTML content
        if ($contentHtml === null && isset($data['raw_response'])) {
            $contentHtml = $data['raw_response'];
            $this->logger->warning('Using raw response as content - JSON parsing failed');
        }

        $result = [
            'title' => $data['title'] ?? $fallbackTitle,
            'meta_title' => $data['meta_title'] ?? $data['title'] ?? $fallbackTitle,
            'meta_description' => $data['meta_description'] ?? '',
            'content_html' => $contentHtml ?? $response,
        ];

        // Validate content quality
        $warnings = $this->validateGeneratedContent($result);
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
            $this->logger->warning('Content validation warnings', ['warnings' => $warnings]);
        }

        return $result;
    }

    /**
     * Validate generated content for quality issues.
     * Returns array of warning messages, empty if content passes all checks.
     */
    private function validateGeneratedContent(array $content): array
    {
        $warnings = [];

        $html = $content['content_html'] ?? '';
        $textContent = strip_tags($html);
        $wordCount = str_word_count($textContent);

        // Check minimum word count
        if ($wordCount < 100 && !empty($html)) {
            $warnings[] = sprintf(
                'Contenu potentiellement tronqué ou trop court (%d mots). Vérifiez le résultat.',
                $wordCount
            );
        }

        // Check if content_html looks like HTML
        if (!empty($html) && !str_contains($html, '<') && strlen($html) > 200) {
            $warnings[] = 'Le contenu généré ne contient pas de HTML. Il pourrait être mal formaté.';
        }

        // Check for truncated HTML (unclosed tags)
        if (!empty($html) && $this->hasUnclosedTags($html)) {
            $warnings[] = 'Le contenu HTML semble tronqué (balises non fermées détectées).';
        }

        // Check meta_title length
        $metaTitle = $content['meta_title'] ?? '';
        if (mb_strlen($metaTitle) > 70) {
            $warnings[] = sprintf('Meta title trop long (%d caractères, max recommandé: 60).', mb_strlen($metaTitle));
        }

        // Check meta_description length
        $metaDesc = $content['meta_description'] ?? '';
        if (mb_strlen($metaDesc) > 170) {
            $warnings[] = sprintf('Meta description trop longue (%d caractères, max recommandé: 155).', mb_strlen($metaDesc));
        }

        return $warnings;
    }

    /**
     * Check if HTML has unclosed major tags (indicates truncation)
     */
    private function hasUnclosedTags(string $html): bool
    {
        $tagsToCheck = ['div', 'section', 'article', 'table', 'ul', 'ol'];

        foreach ($tagsToCheck as $tag) {
            $openCount = preg_match_all("/<{$tag}[\s>]/i", $html);
            $closeCount = preg_match_all("/<\/{$tag}>/i", $html);

            if ($openCount > $closeCount) {
                return true;
            }
        }

        return false;
    }

    public function analyzeKeywords(array $keywords, Project $project): array
    {
        $prompt = $this->buildKeywordAnalysisPrompt($keywords, $project);
        $response = $this->callApi($prompt);

        return $this->parseKeywordAnalysisResponse($response);
    }

    public function generateEditorialCalendar(Project $project, int $weeks = 4): array
    {
        $prompt = $this->buildEditorialCalendarPrompt($project, $weeks);
        $response = $this->callApi($prompt);

        return $this->parseEditorialCalendarResponse($response);
    }

    public function optimizeContent(string $content, string $targetKeyword, Project $project, string $contentType = 'article'): array
    {
        $prompt = $this->buildOptimizationPrompt($content, $targetKeyword, $project, $contentType);
        $response = $this->callApi($prompt);

        return $this->parseOptimizationResponse($response);
    }

    public function generateKeywordContentIdeas(Project $project, string $keyword, array $options = []): array
    {
        $count = $options['count'] ?? 5;
        $types = $options['types'] ?? ['article', 'blog_post'];

        $prompt = $this->buildKeywordContentIdeasPrompt($project, $keyword, $count, $types);
        $response = $this->callApi($prompt);

        return $this->parseContentIdeasResponse($response);
    }

    private function buildKeywordContentIdeasPrompt(Project $project, string $keyword, int $count, array $types): string
    {
        $typesStr = implode(', ', $types);
        $projectContext = $this->buildProjectContext($project);

        return <<<PROMPT
Génère {$count} idées de contenu spécifiquement centrées sur le mot-clé "{$keyword}".

CONTEXTE DU PROJET:
{$projectContext}

MOT-CLÉ CIBLE: {$keyword}
Types de contenu souhaités: {$typesStr}

INSTRUCTIONS IMPORTANTES:
- Chaque idée doit être directement liée au mot-clé "{$keyword}"
- Propose des angles différents et complémentaires pour ce même sujet
- Varie les intentions de recherche (informationnel, transactionnel, comparatif, tutoriel, etc.)
- Prends en compte le ton et le style définis pour le projet
- Adapte la complexité au niveau d'expertise de l'audience
- Les titres doivent être optimisés SEO et contenir le mot-clé ou une variation proche

Réponds en JSON avec ce format:
{
  "ideas": [
    {
      "title": "Titre SEO contenant le mot-clé ou variation",
      "type": "article|blog_post|page|social_post|newsletter|landing_page|product_description",
      "description": "Brève description du contenu et de son angle unique",
      "target_keyword": "{$keyword}",
      "estimated_word_count": 1500,
      "keywords": ["{$keyword}", "variation1", "terme connexe"],
      "priority": "high|medium|low",
      "search_intent": "informational|transactional|commercial|navigational",
      "rationale": "Pourquoi cet angle est pertinent pour ce mot-clé"
    }
  ]
}
PROMPT;
    }

    private function callApiWithTokens(string $prompt, ?string $systemPrompt = null, int $maxRetries = 3, int $maxTokens = self::MAX_TOKENS): string
    {
        $systemPrompt = $systemPrompt ?? $this->getDefaultSystemPrompt();
        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $attempt = 0;
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
                    'timeout' => 600, // 10 minutes timeout for very long content generation
                    'max_duration' => 600,
                    'json' => [
                        'model' => self::MODEL,
                        'max_tokens' => $maxTokens,
                        'system' => $systemPrompt,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray();

                $inputTokens = $data['usage']['input_tokens'] ?? 0;
                $outputTokens = $data['usage']['output_tokens'] ?? 0;

                $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                $cost = ($inputTokens * 0.000003) + ($outputTokens * 0.000015);

                $this->trackApiCall(
                    endpoint: '/v1/messages',
                    method: 'POST',
                    statusCode: $statusCode,
                    success: true,
                    executionTimeMs: $executionTimeMs,
                    metadata: [
                        'model' => self::MODEL,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'max_tokens' => $maxTokens,
                        'attempts' => $attempt,
                    ],
                    customCost: $cost,
                );

                $text = $data['content'][0]['text'] ?? '';
                $stopReason = $data['stop_reason'] ?? null;

                // Detect truncated response
                if ($stopReason === 'max_tokens') {
                    $this->logger->warning('Response truncated (max_tokens reached)', [
                        'output_tokens' => $outputTokens,
                        'max_tokens' => $maxTokens,
                    ]);
                }

                return $text;
            } catch (\Exception $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();

                $isRetryable = str_contains($errorMessage, '429')
                    || str_contains($errorMessage, '529')
                    || str_contains($errorMessage, '500')
                    || str_contains($errorMessage, '502')
                    || str_contains($errorMessage, '503');

                if ($isRetryable && $attempt < $maxRetries) {
                    $waitSeconds = pow(2, $attempt);
                    $this->logger->warning("Claude API error (attempt {$attempt}/{$maxRetries}), retrying in {$waitSeconds}s: " . $e->getMessage());
                    sleep($waitSeconds);
                    continue;
                }

                $this->logger->error('Claude API error: ' . $e->getMessage());
                break;
            }
        }

        $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->trackApiCall(
            endpoint: '/v1/messages',
            method: 'POST',
            statusCode: $statusCode,
            success: false,
            errorMessage: $errorMessage,
            executionTimeMs: $executionTimeMs,
            metadata: [
                'model' => self::MODEL,
                'max_tokens' => $maxTokens,
                'attempts' => $attempt,
            ],
        );

        $userMessage = 'Le service de génération est temporairement indisponible. Veuillez réessayer dans quelques instants.';
        if (str_contains($errorMessage ?? '', '529') || str_contains($errorMessage ?? '', '503')) {
            $userMessage = 'Le service AI est actuellement surchargé. Veuillez réessayer dans quelques minutes.';
        } elseif (str_contains($errorMessage ?? '', '429')) {
            $userMessage = 'Limite de requêtes atteinte. Veuillez patienter quelques instants avant de réessayer.';
        }

        throw new \RuntimeException($userMessage);
    }

    private function callApi(string $prompt, ?string $systemPrompt = null, int $maxRetries = 3): string
    {
        $systemPrompt = $systemPrompt ?? $this->getDefaultSystemPrompt();
        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $attempt = 0;
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
                    'timeout' => 300, // 5 minutes timeout for long content generation
                    'max_duration' => 300, // Max total duration for the request
                    'json' => [
                        'model' => self::MODEL,
                        'max_tokens' => self::MAX_TOKENS,
                        'system' => $systemPrompt,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray();

                // Extract token usage for cost calculation
                $inputTokens = $data['usage']['input_tokens'] ?? 0;
                $outputTokens = $data['usage']['output_tokens'] ?? 0;

                // Success - track and return
                $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                $cost = ($inputTokens * 0.000003) + ($outputTokens * 0.000015);

                $this->trackApiCall(
                    endpoint: '/v1/messages',
                    method: 'POST',
                    statusCode: $statusCode,
                    success: true,
                    executionTimeMs: $executionTimeMs,
                    metadata: [
                        'model' => self::MODEL,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'attempts' => $attempt,
                    ],
                    customCost: $cost,
                );

                $text = $data['content'][0]['text'] ?? '';
                $stopReason = $data['stop_reason'] ?? null;

                if ($stopReason === 'max_tokens') {
                    $this->logger->warning('Response truncated (max_tokens reached) in callApi');
                }

                return $text;
            } catch (\Exception $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();

                // Check if it's a retryable error (429 rate limit, 529 overloaded, 500+ server errors)
                $isRetryable = str_contains($errorMessage, '429')
                    || str_contains($errorMessage, '529')
                    || str_contains($errorMessage, '500')
                    || str_contains($errorMessage, '502')
                    || str_contains($errorMessage, '503');

                if ($isRetryable && $attempt < $maxRetries) {
                    // Exponential backoff: 2s, 4s, 8s
                    $waitSeconds = pow(2, $attempt);
                    $this->logger->warning("Claude API error (attempt {$attempt}/{$maxRetries}), retrying in {$waitSeconds}s: " . $e->getMessage());
                    sleep($waitSeconds);
                    continue;
                }

                $this->logger->error('Claude API error: ' . $e->getMessage());
                break;
            }
        }

        // All retries failed
        $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->trackApiCall(
            endpoint: '/v1/messages',
            method: 'POST',
            statusCode: $statusCode,
            success: false,
            errorMessage: $errorMessage,
            executionTimeMs: $executionTimeMs,
            metadata: [
                'model' => self::MODEL,
                'attempts' => $attempt,
            ],
        );

        // Provide a user-friendly error message
        $userMessage = 'Le service de génération est temporairement indisponible. Veuillez réessayer dans quelques instants.';
        if (str_contains($errorMessage ?? '', '529') || str_contains($errorMessage ?? '', '503')) {
            $userMessage = 'Le service AI est actuellement surchargé. Veuillez réessayer dans quelques minutes.';
        } elseif (str_contains($errorMessage ?? '', '429')) {
            $userMessage = 'Limite de requêtes atteinte. Veuillez patienter quelques instants avant de réessayer.';
        }

        throw new \RuntimeException($userMessage);
    }

    private function trackApiCall(
        ?string $endpoint = null,
        string $method = 'POST',
        ?int $statusCode = null,
        bool $success = true,
        ?string $errorMessage = null,
        ?int $executionTimeMs = null,
        ?array $metadata = null,
        ?float $customCost = null,
    ): void {
        if ($this->apiUsageService === null) {
            return;
        }

        $this->apiUsageService->logApiCall(
            provider: self::PROVIDER_NAME,
            endpoint: $endpoint,
            method: $method,
            statusCode: $statusCode,
            success: $success,
            errorMessage: $errorMessage,
            executionTimeMs: $executionTimeMs,
            metadata: $metadata,
            customCost: $customCost,
        );
    }

    /**
     * Get the year to use in content titles and references.
     * Rule: Before November of year N → use year N
     *       From November of year N → use year N+1
     *
     * This avoids generating "Technologies 2027" in March 2026.
     */
    private function getContentYear(): string
    {
        $now = new \DateTime();
        $currentMonth = (int) $now->format('n');
        $currentYear = (int) $now->format('Y');

        if ($currentMonth >= self::YEAR_THRESHOLD_MONTH) {
            return (string) ($currentYear + 1);
        }

        return (string) $currentYear;
    }

    /**
     * Get data reliability instructions to inject into content generation prompts.
     */
    private function getDataReliabilityRules(string $contentType = 'general'): string
    {
        $rules = <<<RULES
FIABILITÉ DES DONNÉES:
- N'invente AUCUNE statistique, chiffre ou pourcentage. Utilise des formulations qualitatives si tu n'as pas de donnee fiable
- N'invente AUCUNE étude, rapport, institut ou source. Ne cite pas de fausses références
- Si un chiffre précis est nécessaire mais incertain, ajoute [À VÉRIFIER] après celui-ci
- Privilégie les affirmations factuelles générales et vérifiables
RULES;

        // Additional rules for specific content types
        $extraRules = match ($contentType) {
            'case_study' => "\n- Les etudes de cas doivent s'appuyer sur des scenarios realistes. N'invente pas de noms d'entreprises reels. Utilise des descriptions generiques (\"un e-commerce du secteur mode\", \"une PME industrielle\")\n- Les resultats chiffres doivent etre presentes comme des exemples illustratifs: \"ce type de strategie peut generer des ameliorations de l'ordre de...\"",
            'comparison' => "\n- Les comparaisons doivent etre basees sur des criteres objectifs et verifiables\n- Ne presente pas des opinions comme des faits. Utilise \"selon notre experience\" ou \"d'apres les retours utilisateurs\"",
            'tutorial' => "\n- Les instructions techniques doivent etre exactes et testables\n- Si une etape depend d'une version specifique d'un outil, mentionne-le",
            'faq' => "\n- Les reponses doivent etre factuelles. Si une reponse varie selon le contexte, indique-le clairement",
            default => '',
        };

        return $rules . $extraRules;
    }

    private function getDefaultSystemPrompt(): string
    {
        $currentDate = (new \DateTime())->format('d/m/Y');
        $contentYear = $this->getContentYear();

        return <<<PROMPT
Tu es un expert en SEO et marketing de contenu. Tu aides les entreprises à créer du contenu optimisé pour les moteurs de recherche.

DATE ACTUELLE: {$currentDate}
ANNÉE DE RÉFÉRENCE POUR LE CONTENU: {$contentYear}

Règles importantes:
- Réponds toujours dans la langue cible du projet
- Prends en compte le pays cible pour les références culturelles et les tendances locales
- Génère du contenu original et engageant
- Optimise pour le SEO tout en restant naturel et lisible
- Utilise les mots-clés de manière organique
- Structure le contenu avec des titres et sous-titres appropriés
- Quand tu génères du JSON, assure-toi qu'il soit valide et parsable

RÈGLES DE FIABILITÉ DES DONNÉES (CRITIQUES):
- Ne JAMAIS inventer de statistiques, chiffres, pourcentages ou données quantitatives
- Ne JAMAIS attribuer de citations à des personnes ou organisations sans certitude
- Ne JAMAIS inventer de noms d'études, de rapports ou de sources
- Si tu n'es pas certain d'un chiffre ou d'une donnée, utilise des formulations prudentes: "selon les estimations du secteur", "les experts s'accordent à dire", "d'après les tendances observées"
- Privilégie les affirmations générales vérifiables plutôt que des données précises inventées
- Pour les statistiques, utilise des ordres de grandeur ("plus de 70%", "environ la moitié") plutôt que des chiffres faussement précis ("73,2%")
- Ne cite JAMAIS de fausses études ou de faux instituts de recherche
- Quand tu mentionnes des tendances ou évolutions, base-toi sur des faits largement reconnus dans l'industrie
- Préfère les formulations factuelles et vérifiables: "le SEO est un levier majeur d'acquisition" plutôt que "le SEO génère 53,3% du trafic web mondial selon une étude de BrightEdge"
- Si le contenu nécessite des données chiffrées précises, indique clairement qu'elles doivent être vérifiées par le client avec la mention [À VÉRIFIER] ou suggère au rédacteur de sourcer ces informations

RÈGLES DE TEMPORALITÉ CRITIQUES:
- L'année de référence pour les titres, guides et contenus est {$contentYear}
- Ne JAMAIS mentionner 2024 ou des années passées comme si c'était le présent ou le futur
- Pour les titres contenant une année, utilise TOUJOURS {$contentYear} (ex: "Guide {$contentYear}", "Tendances {$contentYear}")
- Pour les guides et tutoriels, indique "mis à jour en {$contentYear}" ou "Guide {$contentYear}"
- Évite les formulations datées comme "en 2024" si nous sommes déjà au-delà
PROMPT;
    }

    private function buildProjectContext(Project $project): string
    {
        $context = [];

        // Basic info
        $context[] = "Entreprise/Projet: {$project->getName()}";
        $context[] = "Industrie: {$project->getIndustry()}";
        $context[] = "Description: {$project->getDescription()}";
        $context[] = "Pays cible: {$project->getTargetCountry()}";
        $context[] = "Langue: {$project->getTargetLanguage()}";

        // Keywords and audience
        if (!empty($project->getKeywords())) {
            $context[] = "Mots-cles principaux: " . implode(', ', $project->getKeywords());
        }
        if (!empty($project->getTargetAudience())) {
            $context[] = "Audience cible: " . implode(', ', $project->getTargetAudience());
        }

        // Tone and style
        if ($project->getTone()) {
            $toneLabels = ['professional' => 'Professionnel', 'casual' => 'Decontracte', 'expert' => 'Expert',
                          'friendly' => 'Amical', 'formal' => 'Formel', 'humorous' => 'Humoristique'];
            $context[] = "Ton de communication: " . ($toneLabels[$project->getTone()] ?? $project->getTone());
        }
        if ($project->getWritingStyle()) {
            $styleLabels = ['technical' => 'Technique', 'simplified' => 'Vulgarise', 'storytelling' => 'Storytelling',
                           'factual' => 'Factuel', 'persuasive' => 'Persuasif'];
            $context[] = "Style d'ecriture: " . ($styleLabels[$project->getWritingStyle()] ?? $project->getWritingStyle());
        }

        // USP and strengths
        if ($project->getUniqueValueProposition()) {
            $context[] = "Proposition de valeur unique: {$project->getUniqueValueProposition()}";
        }
        if (!empty($project->getStrengths())) {
            $context[] = "Points forts: " . implode(', ', $project->getStrengths());
        }

        // Conversion goals
        if ($project->getConversionGoal()) {
            $goalLabels = ['leads' => 'Generation de leads', 'sales' => 'Ventes', 'signups' => 'Inscriptions',
                          'downloads' => 'Telechargements', 'awareness' => 'Notoriete', 'engagement' => 'Engagement'];
            $context[] = "Objectif de conversion: " . ($goalLabels[$project->getConversionGoal()] ?? $project->getConversionGoal());
        }
        if (!empty($project->getCallToActions())) {
            $context[] = "Appels a l'action (CTA): " . implode(', ', $project->getCallToActions());
        }

        // Audience expertise
        if ($project->getAudienceExpertiseLevel()) {
            $levelLabels = ['beginner' => 'Debutant', 'intermediate' => 'Intermediaire', 'expert' => 'Expert', 'mixed' => 'Mixte'];
            $context[] = "Niveau d'expertise de l'audience: " . ($levelLabels[$project->getAudienceExpertiseLevel()] ?? $project->getAudienceExpertiseLevel());
        }
        if (!empty($project->getPainPoints())) {
            $context[] = "Points de douleur de l'audience: " . implode(', ', $project->getPainPoints());
        }
        if (!empty($project->getBuyingCriteria())) {
            $context[] = "Criteres d'achat: " . implode(', ', $project->getBuyingCriteria());
        }

        // Competitors
        if (!empty($project->getCompetitors())) {
            $context[] = "Concurrents principaux: " . implode(', ', $project->getCompetitors());
        }
        if ($project->getCompetitivePositioning()) {
            $context[] = "Positionnement concurrentiel: {$project->getCompetitivePositioning()}";
        }

        // Brand constraints
        if (!empty($project->getBrandKeywords())) {
            $context[] = "Mots-cles de marque a utiliser: " . implode(', ', $project->getBrandKeywords());
        }
        if (!empty($project->getForbiddenWords())) {
            $context[] = "MOTS INTERDITS (ne jamais utiliser): " . implode(', ', $project->getForbiddenWords());
        }
        if ($project->getBrandGuidelines()) {
            $context[] = "Guidelines de marque: {$project->getBrandGuidelines()}";
        }

        // Content preferences
        if ($project->getPreferredContentLength()) {
            $lengthLabels = ['short' => 'Court (<500 mots)', 'medium' => 'Moyen (500-1000 mots)',
                            'long' => 'Long (1000-2000 mots)', 'comprehensive' => 'Complet (>2000 mots)'];
            $context[] = "Longueur de contenu preferee: " . ($lengthLabels[$project->getPreferredContentLength()] ?? $project->getPreferredContentLength());
        }

        return implode("\n", $context);
    }

    private function buildContentIdeasPrompt(Project $project, int $count, array $types): string
    {
        $typesStr = implode(', ', $types);
        $projectContext = $this->buildProjectContext($project);

        return <<<PROMPT
Genere {$count} idees de contenu pour ce projet en adoptant une strategie d'UNIVERS SEMANTIQUE complet:

{$projectContext}
Types de contenu souhaites: {$typesStr}

STRATEGIE UNIVERS SEMANTIQUE (Topical Authority):
Tu dois penser au-dela des mots-cles declares. Identifie l'UNIVERS THEMATIQUE COMPLET du projet:
- Les sujets directement lies aux mots-cles du projet
- Les sujets ANNEXES et COMPLEMENTAIRES que le public cible recherche aussi (ex: pour un ERP agence → gestion commerciale, comptabilite, facturation, planning, RH, tendances du secteur, nouveautes legales)
- Les sujets d'ACTUALITE sectorielle (obligations legales, tendances, innovations)
- Les sujets EDUCATIFS qui repondent aux problemes de l'audience en amont du tunnel de conversion
- Les COMPARATIFS et guides de choix

Organise les idees en 3 a 5 CLUSTERS SEMANTIQUES. Chaque cluster represente un pilier thematique.
Pour chaque cluster, propose:
- 1 contenu PILIER (pillar) = guide complet et exhaustif sur le theme
- 2-3 contenus SATELLITES = articles specifiques qui gravitent autour du pilier
- 1 contenu SUPPORT = actualite, comparatif ou cas pratique lie au cluster

INSTRUCTIONS IMPORTANTES:
- Prends en compte le ton et le style definis pour le projet
- Cible les points de douleur de l'audience pour maximiser l'engagement
- Integre les mots-cles de marque quand c'est pertinent
- N'utilise JAMAIS les mots interdits
- Adapte la complexite au niveau d'expertise de l'audience
- Aligne les idees avec l'objectif de conversion principal
- Les contenus d'univers elargi doivent toujours avoir un LIEN LOGIQUE avec l'activite du projet

Reponds en JSON avec ce format:
{
  "semantic_clusters": [
    {
      "name": "Nom du cluster thematique",
      "description": "Description courte du perimetre de ce cluster",
      "keywords": ["mot-cle1", "mot-cle2", "mot-cle3"]
    }
  ],
  "ideas": [
    {
      "title": "Titre de l'idee",
      "type": "article|blog_post|guide|comprehensive_guide|pillar_page|page|faq|tutorial|case_study|comparison|newsletter|landing_page|product_description",
      "description": "Breve description du contenu",
      "target_keyword": "mot-cle principal vise",
      "estimated_word_count": 1500,
      "keywords": ["mot1", "mot2", "mot3"],
      "priority": "high|medium|low",
      "rationale": "Pourquoi cette idee est pertinente pour l'objectif de conversion et comment elle renforce l'autorite thematique",
      "search_intent": "informational|transactional|commercial|navigational",
      "cluster": "Nom du cluster (doit correspondre a un cluster declare)",
      "content_role": "pillar|satellite|supporting"
    }
  ]
}
PROMPT;
    }

    private function buildContentGenerationPrompt(string $title, string $type, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());

        // Determine word count based on project preferences
        $wordCount = $options['word_count'] ?? match($project->getPreferredContentLength()) {
            'short' => 500,
            'medium' => 800,
            'long' => 1500,
            'comprehensive' => 2500,
            default => 1200,
        };

        // Get tone from project settings or options
        $tone = $options['tone'] ?? match($project->getTone()) {
            'professional' => 'professionnel et expert',
            'casual' => 'decontracte et conversationnel',
            'expert' => 'technique et detaille',
            'friendly' => 'chaleureux et accessible',
            'formal' => 'formel et serieux',
            'humorous' => 'leger et engageant avec une pointe d\'humour',
            default => 'professionnel mais accessible',
        };

        // Get writing style instructions
        $styleInstructions = match($project->getWritingStyle()) {
            'technical' => 'Utilise un vocabulaire technique precis. Inclus des donnees et statistiques quand pertinent.',
            'simplified' => 'Vulgarise les concepts complexes. Utilise des analogies et des exemples concrets.',
            'storytelling' => 'Structure le contenu comme une histoire. Utilise des anecdotes et des cas pratiques.',
            'factual' => 'Base-toi sur des faits et des donnees. Adopte une approche objective et informative.',
            'persuasive' => 'Utilise des arguments convaincants. Anticipe les objections et reponds-y.',
            default => '',
        };

        // Build CTA instructions
        $ctaInstructions = '';
        if (!empty($project->getCallToActions())) {
            $ctas = implode(' ou ', $project->getCallToActions());
            $ctaInstructions = "Inclus un appel a l'action a la fin encourageant a: {$ctas}";
        }

        // Build forbidden words warning
        $forbiddenWarning = '';
        if (!empty($project->getForbiddenWords())) {
            $forbidden = implode(', ', $project->getForbiddenWords());
            $forbiddenWarning = "ATTENTION: N'utilise JAMAIS ces mots/expressions: {$forbidden}";
        }

        // Build brand keywords instruction
        $brandKeywordsInstruction = '';
        if (!empty($project->getBrandKeywords())) {
            $brandKw = implode(', ', $project->getBrandKeywords());
            $brandKeywordsInstruction = "Integre naturellement ces termes de marque: {$brandKw}";
        }

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere un contenu complet de type "{$type}" avec les specifications suivantes:

Titre: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}
IMPORTANT: Ne mentionne JAMAIS 2024 ou des années passées. Quand tu mentionnes une année dans le contenu, utilise {$contentYear}.

SPECIFICATIONS DU CONTENU:
Mots-cles a integrer: {$keywordsStr}
Nombre de mots cible: {$wordCount}
Ton: {$tone}
{$styleInstructions}
{$ctaInstructions}
{$brandKeywordsInstruction}
{$forbiddenWarning}

INSTRUCTIONS DE STRUCTURE HTML OBLIGATOIRES:
1. NE PAS inclure de balise <h1> (le titre sera affiche separement)
2. Utilise une hierarchie de titres correcte et logique:
   - <h2> pour les sections principales (2-4 sections)
   - <h3> pour les sous-sections
   - <h4> si necessaire pour les sous-sous-sections
3. Structure du contenu:
   - Introduction engageante (1-2 paragraphes <p>) qui adresse les points de douleur de l'audience
   - Corps avec sections <h2> bien definies
   - Utilise des listes <ul>/<ol> quand pertinent
   - <strong> pour les termes importants, <em> pour l'emphase
   - Conclusion avec appel a l'action aligne avec l'objectif de conversion
4. Optimisation SEO:
   - Integre le mot-cle principal dans le premier paragraphe
   - Utilise des variations du mot-cle dans les titres h2/h3
   - Paragraphes courts (3-4 phrases max)
   - Au moins une liste a puces ou numerotee

{$this->getDataReliabilityRules($type)}

IMPORTANT: Reponds UNIQUEMENT avec un JSON valide dans ce format exact:
{
  "title": "Le titre optimise SEO du contenu (avec mot-cle principal)",
  "meta_title": "Le meta title SEO de 50-60 caracteres exactement, incluant le mot-cle principal",
  "meta_description": "Meta description SEO de 150-160 caracteres exactement, incluant le mot-cle principal et un appel a l'action",
  "content_html": "<p>Introduction...</p><h2>Section 1</h2><p>Contenu...</p><h3>Sous-section</h3><p>...</p><h2>Section 2</h2>..."
}

REGLES JSON:
- Le champ content_html doit etre une seule chaine de caracteres
- Echappe correctement les guillemets dans le HTML avec \"
- Pas de retours a la ligne dans les valeurs JSON (utilise des espaces)
- HTML valide et bien formate
PROMPT;
    }

    private function buildGuideGenerationPrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());

        // Minimum 3500 words for comprehensive guides
        $wordCount = max($options['word_count'] ?? 3500, 3500);

        // Get tone from project settings or options
        $tone = $options['tone'] ?? match($project->getTone()) {
            'professional' => 'professionnel et expert',
            'casual' => 'decontracte et conversationnel',
            'expert' => 'technique et detaille',
            'friendly' => 'chaleureux et accessible',
            'formal' => 'formel et serieux',
            'humorous' => 'leger et engageant avec une pointe d\'humour',
            default => 'professionnel mais accessible',
        };

        // Get writing style instructions
        $styleInstructions = match($project->getWritingStyle()) {
            'technical' => 'Utilise un vocabulaire technique precis. Inclus des donnees et statistiques quand pertinent.',
            'simplified' => 'Vulgarise les concepts complexes. Utilise des analogies et des exemples concrets.',
            'storytelling' => 'Structure le contenu comme une histoire. Utilise des anecdotes et des cas pratiques.',
            'factual' => 'Base-toi sur des faits et des donnees. Adopte une approche objective et informative.',
            'persuasive' => 'Utilise des arguments convaincants. Anticipe les objections et reponds-y.',
            default => '',
        };

        // Build CTA instructions
        $ctaInstructions = '';
        if (!empty($project->getCallToActions())) {
            $ctas = implode(' ou ', $project->getCallToActions());
            $ctaInstructions = "Inclus un appel a l'action dans la conclusion encourageant a: {$ctas}";
        }

        // Build forbidden words warning
        $forbiddenWarning = '';
        if (!empty($project->getForbiddenWords())) {
            $forbidden = implode(', ', $project->getForbiddenWords());
            $forbiddenWarning = "ATTENTION: N'utilise JAMAIS ces mots/expressions: {$forbidden}";
        }

        // Build brand keywords instruction
        $brandKeywordsInstruction = '';
        if (!empty($project->getBrandKeywords())) {
            $brandKw = implode(', ', $project->getBrandKeywords());
            $brandKeywordsInstruction = "Integre naturellement ces termes de marque: {$brandKw}";
        }

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere un GUIDE COMPLET et EXHAUSTIF avec les specifications suivantes:

Titre: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}
IMPORTANT: Ne mentionne JAMAIS 2024 ou des années passées. Quand tu mentionnes une année dans le contenu, utilise {$contentYear}.

SPECIFICATIONS DU GUIDE:
Mots-cles a integrer: {$keywordsStr}
Nombre de mots MINIMUM: {$wordCount} mots (c'est un MINIMUM, tu peux en ecrire plus)
Ton: {$tone}
{$styleInstructions}
{$ctaInstructions}
{$brandKeywordsInstruction}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE DU GUIDE COMPLET:

1. INTRODUCTION (300-500 mots):
   - Accroche engageante qui capte l'attention
   - Presentation du sujet et de son importance
   - Ce que le lecteur va apprendre
   - A qui s'adresse ce guide
   - Utilise la balise <div class="guide-intro">...</div>

2. TABLE DES MATIERES (generee automatiquement a partir des chapitres):
   - Liste des chapitres avec liens d'ancrage
   - Utilise <nav class="guide-toc"><h2>Sommaire</h2><ul>...</ul></nav>

3. CHAPITRES (5 a 8 chapitres, 400-700 mots chacun):
   - Chaque chapitre commence par <h2 id="chapitre-X">
   - Sous-sections avec <h3> si necessaire
   - Contenu detaille, actionnable et pratique
   - Exemples concrets et cas d'usage
   - Points cles en <ul> ou <ol>
   - Encadres informatifs avec <div class="guide-tip">...</div> ou <div class="guide-warning">...</div>
   - Transitions fluides entre chapitres

4. CONCLUSION (200-400 mots):
   - Resume des points essentiels
   - Prochaines etapes recommandees
   - Appel a l'action
   - Utilise <div class="guide-conclusion">...</div>

5. FAQ (optionnel, 3-5 questions):
   - Questions frequentes sur le sujet
   - Utilise <div class="guide-faq"><h2>Questions frequentes</h2>...</div>
   - Chaque Q/A dans <div class="faq-item"><h3>Question?</h3><p>Reponse...</p></div>

REGLES DE FORMATAGE HTML:
- NE PAS inclure de balise <h1> (le titre sera affiche separement)
- Utiliser des <h2> pour les chapitres principaux avec id pour les ancres
- Utiliser des <h3> pour les sous-sections
- Paragraphes courts et lisibles (3-5 phrases max)
- Listes <ul>/<ol> pour les points importants
- <strong> pour les termes cles
- <blockquote> pour les citations ou points importants a retenir
- Code ou exemples dans <pre><code>...</code></pre> si pertinent

OPTIMISATION SEO:
- Mot-cle principal dans l'introduction et plusieurs chapitres
- Variations du mot-cle dans les titres h2/h3
- Meta description optimisee pour le featured snippet
- Structuration pour les passages optimaux (featured snippets)

IMPORTANT: Ce guide doit etre COMPLET et EXHAUSTIF. Il doit pouvoir servir de reference unique sur le sujet.
La longueur MINIMUM est de {$wordCount} mots. Ne te limite pas - ecris un guide vraiment complet.

{$this->getDataReliabilityRules('comprehensive_guide')}

Reponds UNIQUEMENT avec un JSON valide dans ce format exact:
{
  "title": "Le titre optimise SEO du guide (avec mot-cle principal)",
  "meta_title": "Le meta title SEO de 50-60 caracteres exactement, incluant le mot-cle principal",
  "meta_description": "Meta description SEO de 150-160 caracteres exactement, incluant le mot-cle principal et un appel a l'action",
  "content_html": "<div class=\"guide-intro\">...</div><nav class=\"guide-toc\">...</nav><h2 id=\"chapitre-1\">...</h2>..."
}

REGLES JSON:
- Le champ content_html doit etre une seule chaine de caracteres
- Echappe correctement les guillemets dans le HTML avec \"
- Pas de retours a la ligne dans les valeurs JSON (utilise des espaces)
- HTML valide et bien formate
PROMPT;
    }

    /**
     * Page Pilier (Pillar Page) - Hub de contenu exhaustif pour le maillage interne
     * Structure: Introduction + Table des matières + Sections thématiques + CTA + Liens vers contenus satellites
     */
    private function buildPillarPagePrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['pillar_page'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $styleInstructions = $this->getStyleInstructions($project);
        $ctaInstructions = $this->getCtaInstructions($project);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);
        $brandKeywordsInstruction = $this->getBrandKeywordsInstruction($project);

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere une PAGE PILIER complete et exhaustive.

DEFINITION: Une page pilier est une page de reference qui couvre un sujet de maniere complete et sert de "hub" pour des contenus satellites (articles, guides). Elle doit ranker sur des mots-cles competitifs et generer du maillage interne.

Titre: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}

SPECIFICATIONS:
Mots-cles a integrer: {$keywordsStr}
Nombre de mots MINIMUM: {$wordCount} mots
Ton: {$tone}
{$styleInstructions}
{$ctaInstructions}
{$brandKeywordsInstruction}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE DE LA PAGE PILIER:

1. INTRODUCTION STRATEGIQUE (200-300 mots):
   - Accroche qui positionne l'expertise
   - Definition claire du sujet principal
   - Promesse de valeur pour le lecteur
   - Utilise <div class="pillar-intro">...</div>

2. TABLE DES MATIERES INTERACTIVE:
   - Liste des sections avec liens d'ancrage
   - Utilise <nav class="pillar-toc"><h2>Ce que vous allez apprendre</h2><ul>...</ul></nav>

3. SECTIONS THEMATIQUES (5 a 8 sections, 300-500 mots chacune):
   - Chaque section = <h2 id="section-X">Titre de section</h2>
   - Sous-sections avec <h3> si necessaire
   - Contenu actionnable et pedagogique
   - Points cles en listes <ul>/<ol>
   - Encadres avec <div class="pillar-highlight">Point important...</div>

4. BLOCS CTA INTERCALES (2-3 dans le contenu):
   - Apres les sections 2 et 5 environ
   - Utilise <div class="pillar-cta"><h3>Titre CTA</h3><p>Texte...</p><a href="#" class="btn-cta">Action</a></div>

5. SECTION "CONTENUS ASSOCIES" (pour le maillage interne):
   - Suggere 5-8 sujets d'articles satellites a creer
   - Utilise <div class="pillar-related"><h2>Pour aller plus loin</h2><ul class="related-topics">...</ul></div>
   - Format: <li><strong>Sujet suggere</strong> - Description courte du contenu a creer</li>

6. CONCLUSION AVEC CTA PRINCIPAL:
   - Resume des points cles
   - Appel a l'action fort
   - Utilise <div class="pillar-conclusion">...</div>

7. FAQ INTEGREE (5-8 questions):
   - Questions frequentes sur le sujet
   - Format Schema.org ready
   - Utilise <div class="pillar-faq"><h2>Questions frequentes</h2>...</div>
   - Chaque Q/A: <div class="faq-item"><h3>Question?</h3><div class="faq-answer"><p>Reponse...</p></div></div>

OPTIMISATION SEO:
- Mot-cle principal dans l'intro, plusieurs H2, et la conclusion
- Variations semantiques dans les sous-titres
- Structure optimisee pour les featured snippets
- Densite de mots-cles naturelle (2-3%)

{$this->getDataReliabilityRules('pillar_page')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "Titre SEO optimise avec mot-cle principal",
  "meta_title": "Meta title 50-60 caracteres avec mot-cle",
  "meta_description": "Meta description 150-160 caracteres avec mot-cle et CTA",
  "content_html": "<div class=\"pillar-intro\">...</div><nav class=\"pillar-toc\">...</nav>..."
}

REGLES JSON:
- Le champ content_html doit etre une seule chaine de caracteres
- Echappe correctement les guillemets avec \"
- Pas de retours a la ligne dans les valeurs JSON
- HTML valide et bien formate
PROMPT;
    }

    /**
     * Landing Page - Page de conversion avec structure persuasive
     * Structure: Hero + Benefices + Preuves sociales + CTA multiples
     */
    private function buildLandingPagePrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['landing_page'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $ctaInstructions = $this->getCtaInstructions($project);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere une LANDING PAGE optimisee pour la conversion.

DEFINITION: Une landing page est une page focalisee sur UN SEUL objectif de conversion. Elle doit convaincre et convertir rapidement.

Titre/Sujet: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}

SPECIFICATIONS:
Mots-cles: {$keywordsStr}
Nombre de mots: {$wordCount}
Ton: {$tone}
{$ctaInstructions}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE DE LA LANDING PAGE:

1. SECTION HERO (accroche principale):
   <div class="landing-hero">
     <h2>Titre principal accrocheur avec benefice cle</h2>
     <p class="hero-subtitle">Sous-titre qui developpe la promesse</p>
     <div class="hero-cta"><a href="#" class="btn-primary">CTA Principal</a></div>
   </div>

2. SECTION PROBLEME/SOLUTION:
   <div class="landing-problem">
     <h2>Le probleme que vous rencontrez</h2>
     <ul class="pain-points">Points de douleur...</ul>
   </div>
   <div class="landing-solution">
     <h2>Notre solution</h2>
     <p>Comment nous resolvons ce probleme...</p>
   </div>

3. BENEFICES (3-5 benefices cles):
   <div class="landing-benefits">
     <h2>Ce que vous obtenez</h2>
     <div class="benefit-grid">
       <div class="benefit-item">
         <h3>Benefice 1</h3>
         <p>Description...</p>
       </div>
       ...
     </div>
   </div>

4. PREUVES SOCIALES:
   <div class="landing-social-proof">
     <h2>Ils nous font confiance</h2>
     <div class="testimonial">
       <blockquote>"Citation client..."</blockquote>
       <cite>Nom, Poste, Entreprise</cite>
     </div>
   </div>

5. CTA FINAL:
   <div class="landing-final-cta">
     <h2>Pret a commencer?</h2>
     <p>Phrase d'urgence ou de reassurance</p>
     <a href="#" class="btn-primary btn-large">CTA Final</a>
     <p class="reassurance">Garantie, essai gratuit, etc.</p>
   </div>

REGLES:
- PAS de navigation complexe, focus sur la conversion
- CTA visible et repete 2-3 fois
- Texte court, percutant, oriente benefices
- Eliminer les objections

{$this->getDataReliabilityRules('landing_page')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "Titre de la landing page",
  "meta_title": "Meta title 50-60 caracteres",
  "meta_description": "Meta description 150-160 caracteres avec CTA",
  "content_html": "<div class=\"landing-hero\">...</div>..."
}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * Description Produit - Fiche produit optimisee e-commerce
     * Structure: Benefices + Caracteristiques + Specifications + CTA
     */
    private function buildProductDescriptionPrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['product_description'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);

        return <<<PROMPT
Genere une DESCRIPTION PRODUIT optimisee pour le e-commerce et le SEO.

Produit: {$title}

CONTEXTE DU PROJET:
{$projectContext}

SPECIFICATIONS:
Mots-cles: {$keywordsStr}
Nombre de mots: {$wordCount}
Ton: {$tone}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE:

1. ACCROCHE PRODUIT:
   <div class="product-intro">
     <p class="product-tagline">Phrase d'accroche percutante (1 ligne)</p>
     <p>Description courte orientee benefices (2-3 phrases)</p>
   </div>

2. BENEFICES CLES (3-5 points):
   <div class="product-benefits">
     <h2>Pourquoi choisir ce produit</h2>
     <ul class="benefits-list">
       <li><strong>Benefice 1:</strong> Explication...</li>
       ...
     </ul>
   </div>

3. CARACTERISTIQUES DETAILLEES:
   <div class="product-features">
     <h2>Caracteristiques</h2>
     <ul class="features-list">
       <li>Caracteristique 1</li>
       ...
     </ul>
   </div>

4. SPECIFICATIONS TECHNIQUES (si applicable):
   <div class="product-specs">
     <h2>Specifications</h2>
     <table class="specs-table">
       <tr><th>Specification</th><td>Valeur</td></tr>
       ...
     </table>
   </div>

5. GARANTIE/REASSURANCE:
   <div class="product-reassurance">
     <ul>
       <li>Garantie...</li>
       <li>Livraison...</li>
       <li>SAV...</li>
     </ul>
   </div>

REGLES E-COMMERCE:
- Commencer par les benefices, pas les specs
- Utiliser des bullet points pour la scannabilite
- Integrer les mots-cles naturellement
- Anticiper les questions des acheteurs
- Inclure des elements de reassurance

{$this->getDataReliabilityRules('product_description')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "Nom du produit optimise SEO",
  "meta_title": "Meta title 50-60 caracteres avec mot-cle produit",
  "meta_description": "Meta description 150-160 caracteres avec benefice principal",
  "content_html": "<div class=\"product-intro\">...</div>..."
}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * FAQ - Page de questions frequentes structuree
     * Structure: Questions/Reponses avec schema markup ready
     */
    private function buildFaqPrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['faq'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere une PAGE FAQ complete et optimisee SEO.

Sujet: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}

SPECIFICATIONS:
Mots-cles: {$keywordsStr}
Nombre de mots: {$wordCount}
Ton: {$tone}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE:

1. INTRODUCTION:
   <div class="faq-intro">
     <p>Introduction expliquant le sujet de cette FAQ et a qui elle s'adresse (2-3 phrases)</p>
   </div>

2. CATEGORIES DE QUESTIONS (si applicable, 2-4 categories):
   <div class="faq-section">
     <h2>Categorie 1: Questions generales</h2>

     <div class="faq-item" itemscope itemtype="https://schema.org/Question">
       <h3 itemprop="name">Question 1?</h3>
       <div class="faq-answer" itemscope itemtype="https://schema.org/Answer">
         <div itemprop="text">
           <p>Reponse complete et utile...</p>
         </div>
       </div>
     </div>

     <div class="faq-item" itemscope itemtype="https://schema.org/Question">
       <h3 itemprop="name">Question 2?</h3>
       <div class="faq-answer" itemscope itemtype="https://schema.org/Answer">
         <div itemprop="text">
           <p>Reponse...</p>
         </div>
       </div>
     </div>
   </div>

3. MINIMUM 8-12 QUESTIONS au total, reparties en categories

4. CTA EN FIN DE PAGE:
   <div class="faq-cta">
     <h2>Vous avez d'autres questions?</h2>
     <p>Contactez-nous ou consultez nos ressources.</p>
   </div>

REGLES FAQ:
- Questions formulees comme les utilisateurs les poseraient vraiment
- Reponses claires, directes, et completes (50-150 mots par reponse)
- Integrer les mots-cles dans les questions naturellement
- Structure schema.org pour les rich snippets Google
- Varier les types de questions (quoi, comment, pourquoi, combien, etc.)

{$this->getDataReliabilityRules('faq')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "FAQ: [Sujet] - Toutes vos questions",
  "meta_title": "FAQ [Sujet]: Reponses a vos questions | [Marque]",
  "meta_description": "Trouvez les reponses a toutes vos questions sur [sujet]. FAQ complete et detaillee.",
  "content_html": "<div class=\"faq-intro\">...</div><div class=\"faq-section\">...</div>..."
}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * Tutorial - Guide pratique etape par etape
     * Structure: Objectif + Prerequis + Etapes numerotees + Conclusion
     */
    private function buildTutorialPrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['tutorial'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $styleInstructions = $this->getStyleInstructions($project);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere un TUTORIEL pratique et actionnable.

DEFINITION: Un tutoriel guide l'utilisateur etape par etape pour accomplir une tache concrete. Il doit etre clair, progressif et permettre d'obtenir un resultat.

Sujet: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}

SPECIFICATIONS:
Mots-cles: {$keywordsStr}
Nombre de mots: {$wordCount}
Ton: {$tone}
{$styleInstructions}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE:

1. INTRODUCTION:
   <div class="tutorial-intro">
     <p class="tutorial-goal"><strong>Objectif:</strong> Ce que vous saurez faire a la fin</p>
     <p class="tutorial-time"><strong>Temps estime:</strong> X minutes</p>
     <p class="tutorial-level"><strong>Niveau:</strong> Debutant/Intermediaire/Avance</p>
   </div>

2. PREREQUIS (si applicable):
   <div class="tutorial-prerequisites">
     <h2>Avant de commencer</h2>
     <ul>
       <li>Ce dont vous avez besoin</li>
       <li>Connaissances requises</li>
     </ul>
   </div>

3. ETAPES NUMEROTEES (5-10 etapes):
   <div class="tutorial-steps">
     <div class="tutorial-step">
       <h2><span class="step-number">Etape 1</span> Titre de l'etape</h2>
       <p>Explication de ce qu'on va faire...</p>
       <div class="step-action">
         <p><strong>Action:</strong> Instructions precises...</p>
       </div>
       <div class="step-tip">
         <p><strong>Conseil:</strong> Astuce utile...</p>
       </div>
     </div>

     <div class="tutorial-step">
       <h2><span class="step-number">Etape 2</span> Titre...</h2>
       ...
     </div>
   </div>

4. RECAPITULATIF:
   <div class="tutorial-summary">
     <h2>Recapitulatif</h2>
     <ol class="summary-list">
       <li>Ce que vous avez appris point 1</li>
       <li>Ce que vous avez appris point 2</li>
       ...
     </ol>
   </div>

5. POUR ALLER PLUS LOIN:
   <div class="tutorial-next">
     <h2>Prochaines etapes</h2>
     <p>Suggestions pour approfondir...</p>
   </div>

REGLES TUTORIEL:
- UNE action par etape, claire et precise
- Expliquer le POURQUOI, pas seulement le COMMENT
- Anticiper les erreurs courantes avec des conseils
- Progression logique du simple au complexe
- Resultats verifiables a chaque etape

{$this->getDataReliabilityRules('tutorial')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "Comment [action] - Tutoriel complet",
  "meta_title": "Tutoriel: [Action] en X etapes | Guide pratique",
  "meta_description": "Apprenez a [action] grace a ce tutoriel detaille. Guide etape par etape pour debutants.",
  "content_html": "<div class=\"tutorial-intro\">...</div>..."
}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * Etude de cas - Success story client
     * Structure: Contexte + Defi + Solution + Resultats + Temoignage
     */
    private function buildCaseStudyPrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['case_study'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $ctaInstructions = $this->getCtaInstructions($project);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);

        return <<<PROMPT
Genere une ETUDE DE CAS convaincante.

DEFINITION: Une etude de cas raconte l'histoire d'un client et comment vous l'avez aide a resoudre un probleme. C'est un outil de preuve sociale puissant.

Sujet: {$title}

CONTEXTE DU PROJET:
{$projectContext}

SPECIFICATIONS:
Mots-cles: {$keywordsStr}
Nombre de mots: {$wordCount}
Ton: {$tone}
{$ctaInstructions}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE:

1. EN-TETE RESUME:
   <div class="case-study-header">
     <div class="case-meta">
       <p><strong>Client:</strong> [Nom/Type d'entreprise]</p>
       <p><strong>Secteur:</strong> [Industrie]</p>
       <p><strong>Defi:</strong> [Resume en 1 ligne]</p>
       <p><strong>Resultat cle:</strong> [Chiffre impactant]</p>
     </div>
   </div>

2. LE CONTEXTE:
   <div class="case-study-context">
     <h2>Le contexte</h2>
     <p>Presentation du client, son activite, sa situation initiale...</p>
   </div>

3. LE DEFI:
   <div class="case-study-challenge">
     <h2>Le defi</h2>
     <p>Probleme rencontre, enjeux, obstacles...</p>
     <ul class="challenge-points">
       <li>Point de douleur 1</li>
       <li>Point de douleur 2</li>
     </ul>
   </div>

4. LA SOLUTION:
   <div class="case-study-solution">
     <h2>Notre approche</h2>
     <p>Ce que nous avons mis en place...</p>
     <ol class="solution-steps">
       <li><strong>Phase 1:</strong> Description...</li>
       <li><strong>Phase 2:</strong> Description...</li>
     </ol>
   </div>

5. LES RESULTATS:
   <div class="case-study-results">
     <h2>Les resultats</h2>
     <div class="results-grid">
       <div class="result-item">
         <span class="result-number">+XX%</span>
         <span class="result-label">Metrique 1</span>
       </div>
       <div class="result-item">
         <span class="result-number">-XX%</span>
         <span class="result-label">Metrique 2</span>
       </div>
     </div>
     <p>Details et explications des resultats...</p>
   </div>

6. TEMOIGNAGE CLIENT:
   <div class="case-study-testimonial">
     <blockquote>
       "Citation du client sur son experience..."
     </blockquote>
     <cite>Prenom Nom, Poste, Entreprise</cite>
   </div>

7. CTA:
   <div class="case-study-cta">
     <h2>Vous souhaitez des resultats similaires?</h2>
     <p>Contactez-nous pour discuter de votre projet.</p>
   </div>

{$this->getDataReliabilityRules('case_study')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "Etude de cas: [Client] - [Resultat cle]",
  "meta_title": "Etude de cas [Secteur]: Comment [resultat] | [Marque]",
  "meta_description": "Decouvrez comment [client type] a obtenu [resultat] grace a [solution]. Etude de cas complete.",
  "content_html": "<div class=\"case-study-header\">...</div>..."
}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * Comparatif - Analyse comparative de solutions/produits
     * Structure: Criteres + Tableau comparatif + Analyse + Recommandation
     */
    private function buildComparisonPrompt(string $title, Project $project, array $options): string
    {
        $projectContext = $this->buildProjectContext($project);
        $keywordsStr = implode(', ', $options['keywords'] ?? $project->getKeywords());
        $config = self::CONTENT_TYPE_CONFIG['comparison'];
        $wordCount = $options['word_count'] ?? $config['min_words'];

        $tone = $this->getToneDescription($project, $options);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);

        $contentYear = $this->getContentYear();

        return <<<PROMPT
Genere un ARTICLE COMPARATIF objectif et utile.

DEFINITION: Un comparatif aide les lecteurs a choisir entre plusieurs options en presentant les avantages et inconvenients de chacune.

Sujet: {$title}

CONTEXTE DU PROJET:
{$projectContext}

ANNÉE DE RÉFÉRENCE: {$contentYear}

SPECIFICATIONS:
Mots-cles: {$keywordsStr}
Nombre de mots: {$wordCount}
Ton: {$tone}
{$forbiddenWarning}

STRUCTURE OBLIGATOIRE:

1. INTRODUCTION:
   <div class="comparison-intro">
     <p>Contexte du comparatif, pourquoi ce choix est important...</p>
     <p><strong>Ce comparatif est pour vous si:</strong> [profil lecteur cible]</p>
   </div>

2. RESUME RAPIDE (pour les presses):
   <div class="comparison-summary">
     <h2>En resume</h2>
     <ul class="quick-picks">
       <li><strong>Meilleur pour [usage 1]:</strong> Option X</li>
       <li><strong>Meilleur rapport qualite/prix:</strong> Option Y</li>
       <li><strong>Meilleur pour [usage 2]:</strong> Option Z</li>
     </ul>
   </div>

3. CRITERES DE COMPARAISON:
   <div class="comparison-criteria">
     <h2>Nos criteres d'evaluation</h2>
     <ul>
       <li><strong>Critere 1:</strong> Explication...</li>
       <li><strong>Critere 2:</strong> Explication...</li>
       ...
     </ul>
   </div>

4. TABLEAU COMPARATIF:
   <div class="comparison-table-wrapper">
     <h2>Comparatif detaille</h2>
     <table class="comparison-table">
       <thead>
         <tr>
           <th>Critere</th>
           <th>Option A</th>
           <th>Option B</th>
           <th>Option C</th>
         </tr>
       </thead>
       <tbody>
         <tr>
           <td>Critere 1</td>
           <td>Evaluation A</td>
           <td>Evaluation B</td>
           <td>Evaluation C</td>
         </tr>
         ...
       </tbody>
     </table>
   </div>

5. ANALYSE DETAILLEE DE CHAQUE OPTION:
   <div class="comparison-details">
     <div class="option-analysis">
       <h2>Option A: [Nom]</h2>
       <div class="pros-cons">
         <div class="pros">
           <h3>Points forts</h3>
           <ul><li>...</li></ul>
         </div>
         <div class="cons">
           <h3>Points faibles</h3>
           <ul><li>...</li></ul>
         </div>
       </div>
       <p><strong>Ideal pour:</strong> [profil utilisateur]</p>
     </div>
     ...
   </div>

6. VERDICT ET RECOMMANDATIONS:
   <div class="comparison-verdict">
     <h2>Notre verdict</h2>
     <p>Analyse finale et recommandations selon le profil...</p>
   </div>

REGLES COMPARATIF:
- Rester objectif et factuel
- Presenter avantages ET inconvenients de chaque option
- Aider le lecteur a choisir selon SON besoin
- Donner une recommandation claire a la fin

{$this->getDataReliabilityRules('comparison')}

Reponds UNIQUEMENT avec un JSON valide:
{
  "title": "[Option A] vs [Option B]: Comparatif complet {$contentYear}",
  "meta_title": "[Option A] vs [Option B]: Quel choisir en {$contentYear}?",
  "meta_description": "Comparatif detaille [options]. Decouvrez les avantages, inconvenients et notre verdict pour bien choisir.",
  "content_html": "<div class=\"comparison-intro\">...</div>..."
}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * Helper: Get tone description from project settings
     */
    private function getToneDescription(Project $project, array $options): string
    {
        return $options['tone'] ?? match($project->getTone()) {
            'professional' => 'professionnel et expert',
            'casual' => 'decontracte et conversationnel',
            'expert' => 'technique et detaille',
            'friendly' => 'chaleureux et accessible',
            'formal' => 'formel et serieux',
            'humorous' => 'leger et engageant avec une pointe d\'humour',
            default => 'professionnel mais accessible',
        };
    }

    /**
     * Helper: Get style instructions from project settings
     */
    private function getStyleInstructions(Project $project): string
    {
        return match($project->getWritingStyle()) {
            'technical' => 'Utilise un vocabulaire technique precis. Inclus des donnees et statistiques quand pertinent.',
            'simplified' => 'Vulgarise les concepts complexes. Utilise des analogies et des exemples concrets.',
            'storytelling' => 'Structure le contenu comme une histoire. Utilise des anecdotes et des cas pratiques.',
            'factual' => 'Base-toi sur des faits et des donnees. Adopte une approche objective et informative.',
            'persuasive' => 'Utilise des arguments convaincants. Anticipe les objections et reponds-y.',
            default => '',
        };
    }

    /**
     * Helper: Get CTA instructions from project settings
     */
    private function getCtaInstructions(Project $project): string
    {
        if (!empty($project->getCallToActions())) {
            $ctas = implode(' ou ', $project->getCallToActions());
            return "Inclus des appels a l'action encourageant a: {$ctas}";
        }
        return '';
    }

    /**
     * Helper: Get forbidden words warning from project settings
     */
    private function getForbiddenWordsWarning(Project $project): string
    {
        if (!empty($project->getForbiddenWords())) {
            $forbidden = implode(', ', $project->getForbiddenWords());
            return "ATTENTION: N'utilise JAMAIS ces mots/expressions: {$forbidden}";
        }
        return '';
    }

    /**
     * Helper: Get brand keywords instruction from project settings
     */
    private function getBrandKeywordsInstruction(Project $project): string
    {
        if (!empty($project->getBrandKeywords())) {
            $brandKw = implode(', ', $project->getBrandKeywords());
            return "Integre naturellement ces termes de marque: {$brandKw}";
        }
        return '';
    }

    private function buildKeywordAnalysisPrompt(array $keywords, Project $project): string
    {
        $keywordsStr = implode(', ', $keywords);

        return <<<PROMPT
Analyse ces mots-clés pour le projet "{$project->getName()}" dans l'industrie "{$project->getIndustry()}":

Mots-clés: {$keywordsStr}
Pays cible: {$project->getTargetCountry()}
Langue: {$project->getTargetLanguage()}

Réponds en JSON avec ce format:
{
  "analysis": [
    {
      "keyword": "mot-clé",
      "relevance_score": 8,
      "competition_estimate": "high|medium|low",
      "content_suggestions": ["suggestion1", "suggestion2"],
      "related_keywords": ["related1", "related2"],
      "search_intent": "informational|transactional|navigational"
    }
  ],
  "recommendations": {
    "priority_keywords": ["kw1", "kw2"],
    "long_tail_opportunities": ["phrase longue 1", "phrase longue 2"],
    "content_gaps": ["opportunité 1", "opportunité 2"]
  }
}
PROMPT;
    }

    private function buildEditorialCalendarPrompt(Project $project, int $weeks): string
    {
        $projectContext = $this->buildProjectContext($project);

        // Preferred content types
        $preferredTypes = $project->getPreferredContentTypes();
        $typesStr = !empty($preferredTypes)
            ? implode(', ', $preferredTypes)
            : 'article, blog_post, page, social_post';

        return <<<PROMPT
Cree un calendrier editorial sur {$weeks} semaines pour:

{$projectContext}

Types de contenus a privilegier: {$typesStr}

INSTRUCTIONS IMPORTANTES:
- Aligne chaque contenu avec l'objectif de conversion principal
- Adresse les points de douleur de l'audience de maniere progressive
- Respecte le ton et le style definis pour le projet
- N'utilise JAMAIS les mots interdits dans les titres
- Varie les types de contenus pour maintenir l'engagement
- Cree une progression logique dans les themes

Reponds en JSON avec ce format:
{
  "calendar": [
    {
      "week": 1,
      "theme": "Theme de la semaine lie a un point de douleur ou objectif",
      "contents": [
        {
          "day": "monday",
          "type": "blog_post",
          "title": "Titre suggere optimise SEO",
          "target_keyword": "mot-cle cible",
          "description": "Description courte incluant l'angle et la valeur ajoutee"
        }
      ]
    }
  ],
  "strategy_notes": "Notes sur la strategie globale et comment elle s'aligne avec les objectifs de conversion"
}
PROMPT;
    }

    private function buildOptimizationPrompt(string $content, string $targetKeyword, Project $project, string $contentType = 'article'): string
    {
        $projectContext = $this->buildProjectContext($project);

        $contentTypeLabels = [
            'article' => 'Article de blog',
            'page' => 'Page web',
            'product_description' => 'Description de produit',
            'product_category' => 'Page de catégorie produit',
        ];
        $typeLabel = $contentTypeLabels[$contentType] ?? 'Article';

        return <<<PROMPT
Tu es un expert SEO senior. Analyse et optimise ce contenu pour le référencement naturel.

CONTEXTE DU PROJET:
{$projectContext}

TYPE DE CONTENU: {$typeLabel}
MOT-CLÉ CIBLE: {$targetKeyword}

CONTENU ACTUEL À OPTIMISER:
---
{$content}
---

INSTRUCTIONS D'ANALYSE:
1. Évalue le score SEO global (0-100) en tenant compte de : densité du mot-clé, structure Hn, lisibilité, meta, ton
2. Identifie les problèmes concrets avec des suggestions actionnables
3. Propose une version optimisée du contenu qui :
   - Conserve le sens et le message original
   - Améliore la densité naturelle du mot-clé cible "{$targetKeyword}" et ses variations
   - Structure le contenu avec des balises HTML sémantiques (h2, h3, p, ul, li, strong)
   - Améliore la lisibilité (phrases courtes, paragraphes aérés)
   - Respecte le ton du projet s'il est défini
4. Propose une meta description optimisée (max 155 caractères) contenant le mot-clé
5. Propose 3 titres SEO alternatifs contenant le mot-clé

RÈGLES POUR LE CONTENU OPTIMISÉ:
- Le champ "optimized_content" DOIT être du HTML valide (utilise <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em>)
- NE PAS utiliser de markdown (pas de **, ##, -, etc.)
- NE PAS inclure de balise <h1> (elle est gérée séparément)
- Garde une longueur similaire au contenu original (+/- 20%)
- Le contenu doit être prêt à être intégré dans une page web

Réponds UNIQUEMENT en JSON valide avec ce format exact:
{
  "seo_score": 75,
  "issues": [
    {
      "type": "keyword_density|structure|readability|meta|tone|brand_compliance",
      "severity": "high|medium|low",
      "description": "Description claire du problème",
      "suggestion": "Action concrète pour corriger"
    }
  ],
  "optimized_content": "<h2>Titre de section</h2><p>Paragraphe optimisé...</p>",
  "meta_description": "Meta description optimisée de moins de 155 caractères",
  "title_suggestions": ["Titre SEO 1", "Titre SEO 2", "Titre SEO 3"]
}
PROMPT;
    }

    private function parseContentIdeasResponse(string $response): array
    {
        $data = $this->extractJson($response);

        if ($data === null) {
            $this->logger->warning('Failed to parse JSON response for ideas');
            return ['ideas' => [], 'semantic_clusters' => []];
        }

        return [
            'ideas' => $data['ideas'] ?? [],
            'semantic_clusters' => $data['semantic_clusters'] ?? [],
        ];
    }

    private function parseKeywordAnalysisResponse(string $response): array
    {
        return $this->parseJsonResponse($response);
    }

    private function parseEditorialCalendarResponse(string $response): array
    {
        return $this->parseJsonResponse($response);
    }

    private function parseOptimizationResponse(string $response): array
    {
        return $this->parseJsonResponse($response);
    }

    private function parseJsonResponse(string $response, ?string $key = null): array
    {
        $data = $this->extractJson($response);

        if ($data === null) {
            $this->logger->warning('Failed to parse JSON response, returning raw content');
            return ['raw_response' => $response];
        }

        if ($key !== null && isset($data[$key])) {
            return $data[$key];
        }

        return $data;
    }

    /**
     * Extract JSON from AI response with multiple fallback strategies:
     * 1. Direct JSON decode
     * 2. Extract from markdown code blocks (```json ... ```)
     * 3. Extract first JSON object with regex
     * 4. Attempt JSON repair (trailing commas, unescaped newlines)
     */
    private function extractJson(string $response): ?array
    {
        // Strategy 1: Direct decode
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\JsonException) {
        }

        // Strategy 2: Extract from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $response, $matches)) {
            try {
                $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }

        // Strategy 3: Extract first JSON object with regex
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $jsonStr = $matches[0];
            try {
                $decoded = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }

            // Strategy 4: Attempt JSON repair
            $repaired = $this->repairJson($jsonStr);
            if ($repaired !== null) {
                return $repaired;
            }
        }

        return null;
    }

    /**
     * Attempt to repair common JSON issues from AI responses:
     * - Trailing commas before } or ]
     * - Unescaped newlines in strings
     * - Single quotes instead of double quotes
     */
    private function repairJson(string $json): ?array
    {
        // Remove trailing commas before } or ]
        $repaired = preg_replace('/,\s*([\}\]])/', '$1', $json);

        // Try decoding after trailing comma fix
        try {
            $decoded = json_decode($repaired, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $this->logger->info('JSON repaired successfully (trailing commas)');
                return $decoded;
            }
        } catch (\JsonException) {
        }

        // Replace unescaped newlines within string values
        $repaired = preg_replace('/(?<=":[ ]?"[^"]*)\n(?=[^"]*")/', '\\n', $repaired);
        try {
            $decoded = json_decode($repaired, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $this->logger->info('JSON repaired successfully (unescaped newlines)');
                return $decoded;
            }
        } catch (\JsonException) {
        }

        return null;
    }

    // ==================== LOCAL SEO CONTENT GENERATION ====================

    /**
     * Generate local landing page content
     */
    public function generateLocalContent(
        Project $project,
        \SeoExpert\Engine\Entity\Location $location,
        string $service,
        string $targetKeyword,
        array $localContext = []
    ): array {
        $prompt = $this->buildLocalLandingPagePrompt(
            $project,
            $location,
            $service,
            $targetKeyword,
            $localContext
        );

        $config = self::CONTENT_TYPE_CONFIG['landing_page'];
        $response = $this->callApiWithTokens($prompt, null, 3, $config['tokens']);

        return $this->parseContentResponse($response, $targetKeyword);
    }

    /**
     * Generate geo zone hub page (pillar content for a geographic area)
     */
    public function generateGeoZoneHub(
        Project $project,
        \SeoExpert\Engine\Entity\GeoZone $zone,
        array $locationNames,
        string $primaryService
    ): array {
        $prompt = $this->buildGeoZoneHubPrompt($project, $zone, $locationNames, $primaryService);

        $config = self::CONTENT_TYPE_CONFIG['pillar_page'];
        $response = $this->callApiWithTokens($prompt, null, 3, $config['tokens']);

        return $this->parseContentResponse($response, $zone->getName());
    }

    /**
     * Build prompt for local landing page
     */
    private function buildLocalLandingPagePrompt(
        Project $project,
        \SeoExpert\Engine\Entity\Location $location,
        string $service,
        string $targetKeyword,
        array $localContext
    ): string {
        $projectContext = $this->buildProjectContext($project);
        $locationContext = $this->buildLocationContext($location, $localContext);

        $tone = $this->getToneDescription($project, []);
        $styleInstructions = $this->getStyleInstructions($project);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);
        $brandKeywords = $this->getBrandKeywordsInstruction($project);
        $ctaInstructions = $this->getCtaInstructions($project);

        $contentYear = $this->getContentYear();
        $locationName = $location->getName();

        return <<<PROMPT
Genere une PAGE DE SERVICE LOCALE optimisee pour le SEO local.

DEFINITION: Une page de service locale cible une requete geographique specifique
(ex: "plombier Paris 15", "avocat Marseille"). Elle doit etre unique, pertinente
localement et optimisee pour le Pack Local Google.

SERVICE: {$service}
MOT-CLE CIBLE: {$targetKeyword}
ANNÉE DE RÉFÉRENCE: {$contentYear}

CONTEXTE DU PROJET:
{$projectContext}

CONTEXTE LOCAL:
{$locationContext}

INSTRUCTIONS DE STYLE:
Ton: {$tone}
{$styleInstructions}
{$forbiddenWarning}
{$brandKeywords}
{$ctaInstructions}

STRUCTURE OBLIGATOIRE:

1. HERO LOCAL:
   <div class="local-hero">
     <h1>[Mot-cle principal optimise pour {$locationName}]</h1>
     <p class="local-subtitle">Description courte avec mention de la zone geographique</p>
     <div class="local-cta">
       <a href="tel:XXXXXXXXXX" class="btn-primary">Appeler maintenant</a>
       <a href="#contact" class="btn-secondary">Demander un devis</a>
     </div>
   </div>

2. INTRODUCTION LOCALE (150-200 mots):
   <div class="local-intro">
     <p>Presentation du service dans le contexte local de {$locationName}...</p>
   </div>

3. ZONE D'INTERVENTION:
   <div class="service-area">
     <h2>Notre zone d'intervention a {$locationName}</h2>
     <p>Description de la couverture geographique...</p>
     <ul class="area-list">
       <li>Quartier/ville 1</li>
       <li>Quartier/ville 2</li>
       <li>Quartier/ville 3</li>
     </ul>
   </div>

4. SERVICES PROPOSES (3-5 services):
   <div class="local-services">
     <h2>Nos services de {$service} a {$locationName}</h2>
     <div class="service-grid">
       <div class="service-item">
         <h3>Service 1</h3>
         <p>Description avec detail local...</p>
       </div>
       <!-- Autres services -->
     </div>
   </div>

5. POURQUOI NOUS CHOISIR (4-5 avantages):
   <div class="local-benefits">
     <h2>Pourquoi choisir notre {$service} a {$locationName}</h2>
     <ul>
       <li><strong>Avantage 1:</strong> Explication avec contexte local</li>
       <!-- Autres avantages -->
     </ul>
   </div>

6. TEMOIGNAGES LOCAUX (2-3):
   <div class="local-testimonials">
     <h2>Ce que disent nos clients a {$locationName}</h2>
     <blockquote>
       <p>"Temoignage fictif mais realiste..."</p>
       <cite>- Prenom N., {$locationName}</cite>
     </blockquote>
   </div>

7. FAQ LOCALE (4-6 questions):
   <div class="local-faq">
     <h2>Questions frequentes sur nos services a {$locationName}</h2>
     <details>
       <summary>Question locale 1?</summary>
       <p>Reponse...</p>
     </details>
   </div>

8. CTA FINAL AVEC CONTACT:
   <div class="local-contact" id="contact">
     <h2>Contactez votre {$service} a {$locationName}</h2>
     <p>Intervention rapide dans tout {$locationName} et ses environs.</p>
     <div class="contact-cta">
       <a href="tel:XXXXXXXXXX" class="btn-primary">Appelez-nous</a>
     </div>
   </div>

REGLES SEO LOCAL CRITIQUES:
- Mentionner le nom de la ville/zone 5-8 fois naturellement dans le contenu
- Inclure des references locales specifiques (quartiers, monuments, points de repere)
- Utiliser des variations du mot-cle local dans les H2/H3
- Chaque page doit etre UNIQUE - eviter le contenu duplique entre villes
- Preparer le contenu pour le schema LocalBusiness
- Longueur totale: 800-1200 mots

{$this->getDataReliabilityRules('landing_page')}

Reponds UNIQUEMENT avec un JSON valide:
{{
  "title": "{$targetKeyword} - Expert local | {$project->getName()}",
  "meta_title": "{$targetKeyword} | Devis gratuit | Intervention rapide",
  "meta_description": "Besoin d'un {$service} a {$locationName}? {$project->getName()}: intervention rapide, devis gratuit, satisfaction garantie. Contactez-nous!",
  "content_html": "<div class=\"local-hero\">...</div>..."
}}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }

    /**
     * Build location context for prompts
     */
    private function buildLocationContext(\SeoExpert\Engine\Entity\Location $location, array $localContext = []): string
    {
        $context = [];

        $context[] = "Ville/Zone: {$location->getName()}";
        $context[] = "Type: " . match($location->getType()) {
            \SeoExpert\Engine\Entity\Location::TYPE_CITY => 'Ville',
            \SeoExpert\Engine\Entity\Location::TYPE_DEPARTMENT => 'Departement',
            \SeoExpert\Engine\Entity\Location::TYPE_REGION => 'Region',
            default => 'Zone',
        };

        if ($location->getPostalCode()) {
            $context[] = "Code postal: {$location->getPostalCode()}";
        }
        if ($location->getDepartmentCode()) {
            $context[] = "Departement: {$location->getDepartmentCode()}";
        }
        if ($localContext['departmentName'] ?? null) {
            $context[] = "Nom du departement: {$localContext['departmentName']}";
        }
        if ($localContext['regionName'] ?? null) {
            $context[] = "Region: {$localContext['regionName']}";
        }
        if ($location->getPopulation()) {
            $context[] = "Population: " . number_format($location->getPopulation(), 0, ',', ' ') . " habitants";
        }

        // Nearby cities for local references
        if (!empty($localContext['nearbyCities'])) {
            $nearbyNames = array_column(array_slice($localContext['nearbyCities'], 0, 5), 'name');
            $context[] = "Villes proches: " . implode(', ', $nearbyNames);
        }

        return implode("\n", $context);
    }

    /**
     * Build prompt for geo zone hub page (pillar content)
     */
    private function buildGeoZoneHubPrompt(
        Project $project,
        \SeoExpert\Engine\Entity\GeoZone $zone,
        array $locationNames,
        string $primaryService
    ): string {
        $projectContext = $this->buildProjectContext($project);

        $tone = $this->getToneDescription($project, []);
        $styleInstructions = $this->getStyleInstructions($project);
        $forbiddenWarning = $this->getForbiddenWordsWarning($project);
        $brandKeywords = $this->getBrandKeywordsInstruction($project);

        $contentYear = $this->getContentYear();
        $locationsStr = implode(', ', $locationNames);
        $zoneName = $zone->getName();

        return <<<PROMPT
Genere une PAGE PILIER (Hub) pour une zone geographique.

DEFINITION: Une page pilier pour une zone geographique est un contenu complet qui:
- Presente le service/activite pour l'ensemble de la zone
- Sert de page centrale vers les pages satellites (villes individuelles)
- Etablit l'autorite sur la zone geographique complete
- Optimise pour des requetes regionales/departementales

ZONE GEOGRAPHIQUE: {$zoneName}
SERVICE PRINCIPAL: {$primaryService}
VILLES DE LA ZONE: {$locationsStr}
ANNÉE DE RÉFÉRENCE: {$contentYear}

CONTEXTE DU PROJET:
{$projectContext}

INSTRUCTIONS DE STYLE:
Ton: {$tone}
{$styleInstructions}
{$forbiddenWarning}
{$brandKeywords}

STRUCTURE OBLIGATOIRE (3000-4000 mots):

1. INTRODUCTION (300-400 mots):
   <div class="zone-intro">
     <h1>{$primaryService} dans {$zoneName} - Guide complet {$contentYear}</h1>
     <p class="lead">Introduction generale sur le service dans la zone...</p>
     <div class="zone-overview">
       <p>Presentation de la zone et de la couverture...</p>
     </div>
   </div>

2. SOMMAIRE INTERACTIF:
   <nav class="zone-toc">
     <h2>Sommaire</h2>
     <ul>
       <li><a href="#services">Nos services</a></li>
       <li><a href="#villes">Interventions par ville</a></li>
       <li><a href="#avantages">Pourquoi nous choisir</a></li>
       <li><a href="#faq">Questions frequentes</a></li>
     </ul>
   </nav>

3. PRESENTATION DES SERVICES (500-600 mots):
   <section id="services" class="zone-services">
     <h2>{$primaryService}: nos expertises dans {$zoneName}</h2>
     <!-- Details des services avec contexte regional -->
   </section>

4. MAILLAGE VERS LES VILLES (liste de toutes les villes):
   <section id="villes" class="zone-cities">
     <h2>Nos interventions dans {$zoneName}</h2>
     <p>Introduction sur la couverture geographique...</p>
     <div class="cities-grid">
       <!-- Pour chaque ville: lien vers page satellite -->
       <div class="city-card">
         <h3>{$primaryService} [Ville]</h3>
         <p>Description courte unique pour cette ville...</p>
         <a href="#">En savoir plus</a>
       </div>
     </div>
   </section>

5. AVANTAGES REGIONAUX (400-500 mots):
   <section id="avantages" class="zone-benefits">
     <h2>Pourquoi choisir notre {$primaryService} dans {$zoneName}</h2>
     <!-- Avantages specifiques a la zone -->
   </section>

6. TEMOIGNAGES DE LA ZONE:
   <section class="zone-testimonials">
     <h2>Ils nous font confiance dans {$zoneName}</h2>
     <!-- 3-4 temoignages de differentes villes -->
   </section>

7. FAQ REGIONALE (6-8 questions):
   <section id="faq" class="zone-faq">
     <h2>Questions frequentes sur nos services dans {$zoneName}</h2>
     <!-- Questions specifiques a la zone -->
   </section>

8. CTA FINAL:
   <section class="zone-cta">
     <h2>Besoin d'un {$primaryService} dans {$zoneName}?</h2>
     <p>Contactez-nous pour une intervention rapide dans toute la zone.</p>
   </section>

REGLES SEO POUR PAGE PILIER:
- Contenu exhaustif et reference sur le sujet
- Liens internes vers TOUTES les pages satellites (villes)
- Mots-cles regionaux/departementaux dans les H2
- Structure claire avec ancres pour navigation
- Optimise pour requetes larges (zone) ET specifiques (villes)

{$this->getDataReliabilityRules('pillar_page')}

Reponds UNIQUEMENT avec un JSON valide:
{{
  "title": "{$primaryService} dans {$zoneName} - Guide complet {$contentYear}",
  "meta_title": "{$primaryService} {$zoneName} | Toutes les villes | {$project->getName()}",
  "meta_description": "Expert {$primaryService} dans tout {$zoneName}. Intervention rapide a {$locationsStr}. Devis gratuit, satisfaction garantie.",
  "content_html": "<div class=\"zone-intro\">...</div>..."
}}

REGLES JSON: content_html = chaine unique, guillemets echappes, pas de retours a la ligne.
PROMPT;
    }
}
