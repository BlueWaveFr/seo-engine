<?php

namespace SeoExpert\Engine\Service\Agent;

use SeoExpert\Engine\Entity\ClientContext;
use SeoExpert\Engine\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ClientContextService
{
    private const MAX_RECENT_ACTIONS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Load the client context for a project, creating one if it does not exist.
     */
    public function getOrCreateContext(Project $project): ClientContext
    {
        $context = $this->entityManager->getRepository(ClientContext::class)->findOneBy([
            'project' => $project,
        ]);

        if ($context !== null) {
            return $context;
        }

        $context = new ClientContext();
        $context->setProject($project);
        $context->setDomain($this->extractDomain($project->getWebsiteUrl()));

        $this->entityManager->persist($context);
        $this->entityManager->flush();

        $this->logger->info('Created new client context', [
            'project_id' => $project->getId(),
            'domain' => $context->getDomain(),
        ]);

        return $context;
    }

    /**
     * Record an action in the sliding window of recent actions and persist.
     *
     * @param array $actionData e.g. ['tool' => 'run_technical_audit', 'result_summary' => '...', 'date' => '...']
     */
    public function recordAction(ClientContext $context, array $actionData): void
    {
        $actionData['date'] = $actionData['date'] ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $context->addRecentAction($actionData);

        $this->entityManager->flush();
    }

    /**
     * Record a decision in the context history.
     */
    public function recordDecision(ClientContext $context, string $decision, ?string $reasoning = null): void
    {
        $history = $context->getDecisionsHistory();
        $history[] = [
            'decision' => $decision,
            'reasoning' => $reasoning,
            'date' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        // Keep only the last 50 decisions
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        $context->setDecisionsHistory($history);
        $this->entityManager->flush();
    }

    /**
     * Update baseline metrics after a task completes (e.g. new audit scores).
     */
    public function updateBaselineMetrics(ClientContext $context, array $newMetrics): void
    {
        $baseline = $context->getBaselineMetrics();
        $baseline = array_merge($baseline, $newMetrics);
        $baseline['last_updated'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $context->setBaselineMetrics($baseline);
        $this->entityManager->flush();
    }

    /**
     * Build the context string to inject into Claude's system prompt.
     */
    public function buildContextPrompt(ClientContext $context): string
    {
        $parts = [];

        // Domain & industry
        $parts[] = sprintf('## Project Context');
        $parts[] = sprintf('- Domain: %s', $context->getDomain() ?? 'N/A');
        $parts[] = sprintf('- Industry: %s', $context->getIndustry() ?? 'N/A');

        // Traffic goal
        if ($context->getTrafficGoal() !== null) {
            $parts[] = sprintf('- Monthly organic traffic goal: %d', $context->getTrafficGoal());
        }

        // Competitors
        $competitors = $context->getCompetitors();
        if (!empty($competitors)) {
            $parts[] = sprintf('- Competitors: %s', implode(', ', $competitors));
        }

        // Target keywords
        $keywords = $context->getTargetKeywords();
        if (!empty($keywords)) {
            $parts[] = sprintf('- Target keywords: %s', implode(', ', array_slice($keywords, 0, 20)));
        }

        // Editorial rules
        if ($context->getEditorialRules() !== null) {
            $parts[] = '';
            $parts[] = '## Editorial Rules';
            $parts[] = $context->getEditorialRules();
        }

        // Baseline metrics
        $metrics = $context->getBaselineMetrics();
        if (!empty($metrics)) {
            $parts[] = '';
            $parts[] = '## Current Baseline Metrics';
            foreach ($metrics as $key => $value) {
                if ($key === 'last_updated') {
                    continue;
                }
                $parts[] = sprintf('- %s: %s', str_replace('_', ' ', ucfirst($key)), is_array($value) ? json_encode($value) : $value);
            }
        }

        // Recent actions (last 10 for prompt brevity)
        $recentActions = $context->getRecentActions();
        if (!empty($recentActions)) {
            $parts[] = '';
            $parts[] = '## Recent Actions (last 10)';
            $lastActions = array_slice($recentActions, -10);
            foreach ($lastActions as $action) {
                $tool = $action['tool'] ?? 'unknown';
                $date = $action['date'] ?? '';
                $summary = $action['result_summary'] ?? '';
                $parts[] = sprintf('- [%s] %s: %s', $date, $tool, $summary);
            }
        }

        // Recent decisions (last 5)
        $decisions = $context->getDecisionsHistory();
        if (!empty($decisions)) {
            $parts[] = '';
            $parts[] = '## Recent Decisions (last 5)';
            $lastDecisions = array_slice($decisions, -5);
            foreach ($lastDecisions as $decision) {
                $parts[] = sprintf('- [%s] %s', $decision['date'] ?? '', $decision['decision'] ?? '');
            }
        }

        return implode("\n", $parts);
    }

    private function extractDomain(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ?: $url;
    }
}
