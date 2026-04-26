<?php

declare(strict_types=1);

namespace SeoExpert\Engine\Service\Agent;

use SeoExpert\Engine\Entity\AgentTask;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends email notifications related to Agent Mode lifecycle events.
 *
 * Designed to be graceful: every public method swallows mailer errors and
 * logs them — a broken SMTP must never break the orchestration flow.
 *
 * TODO: when a user-level notification-preferences system is introduced,
 *       honor an `agent_email_notifications` toggle here. Today the User
 *       entity has no such field, so the email always fires.
 */
class AgentNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail = 'noreply@optimize360.fr',
        private readonly string $fromName = 'WaveRank Agent',
        private readonly string $appEnv = 'prod',
        private bool $forceAgentEmails = false,
    ) {}

    /**
     * Runtime override — lets CLI commands force-send in dev without changing env.
     */
    public function forceSend(bool $force = true): void
    {
        $this->forceAgentEmails = $force;
    }

    /**
     * Send an "approval required" email to the task owner.
     *
     * Called right after AgentOrchestrator::planTask() persists a task as
     * `awaiting_approval`. Silent on failure by design.
     */
    public function sendApprovalRequiredEmail(AgentTask $task): void
    {
        // Only send for tasks that are actually awaiting approval.
        if ($task->getStatus() !== AgentTask::STATUS_AWAITING_APPROVAL) {
            return;
        }

        // Dev-environment guard: skip unless explicitly forced.
        if ($this->appEnv === 'dev' && !$this->forceAgentEmails) {
            $this->logger->info('Agent approval email skipped (dev env, FORCE_AGENT_EMAILS not set).', [
                'task_id' => $task->getId()->toRfc4122(),
            ]);
            return;
        }

        try {
            $user = $task->getUser();
            $project = $task->getProject();
            $plan = $task->getPlan() ?? [];
            $reasoning = $task->getLlmReasoning() ?? '';

            $reasoningExcerpt = $this->buildReasoningExcerpt($reasoning);
            $steps = $this->normalizePlanForDisplay($plan);
            $taskTypeLabel = $this->getTaskTypeLabel($task->getTaskType());
            $inboxUrl = rtrim($_ENV['APP_URL'] ?? 'https://app.waverank.io', '/') . '/agent/inbox';

            $vars = [
                'user' => $user,
                'project' => $project,
                'task' => $task,
                'taskTypeLabel' => $taskTypeLabel,
                'stepsCount' => count($steps),
                'steps' => $steps,
                'reasoningExcerpt' => $reasoningExcerpt,
                'inboxUrl' => $inboxUrl,
            ];

            $html = $this->twig->render('emails/agent/approval_required.html.twig', $vars);
            $text = $this->twig->render('emails/agent/approval_required.txt.twig', $vars);

            $subject = sprintf(
                "🤖 L'Agent IA WaveRank a préparé un plan pour %s",
                $project->getName(),
            );

            $email = (new Email())
                ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
                ->to($user->getEmail())
                ->subject($subject)
                ->text($text)
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Agent approval email sent.', [
                'task_id' => $task->getId()->toRfc4122(),
                'recipient' => $user->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send agent approval email.', [
                'task_id' => $task->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            // swallow — never break the orchestrator
        }
    }

    /**
     * Truncate reasoning to the first 300 chars on a word boundary.
     */
    private function buildReasoningExcerpt(string $reasoning): string
    {
        $reasoning = trim($reasoning);
        if ($reasoning === '') {
            return '';
        }

        if (mb_strlen($reasoning) <= 300) {
            return $reasoning;
        }

        $excerpt = mb_substr($reasoning, 0, 300);
        $lastSpace = mb_strrpos($excerpt, ' ');
        if ($lastSpace !== false && $lastSpace > 200) {
            $excerpt = mb_substr($excerpt, 0, $lastSpace);
        }
        return rtrim($excerpt, " ,.;:-") . '…';
    }

    /**
     * Normalize each plan entry to {tool, label, description, parameters} for
     * clean rendering in the template.
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array{tool: string, label: string, description: string, parameters: array}>
     */
    private function normalizePlanForDisplay(array $plan): array
    {
        $labels = [
            'run_technical_audit'       => ['Audit technique',          "Analyse complète de la santé technique du site."],
            'get_keyword_opportunities' => ['Opportunités de mots-clés', "Identification de nouveaux mots-clés à cibler."],
            'generate_content_brief'    => ['Brief de contenu',         "Génération d'un brief SEO pour un mot-clé cible."],
            'check_position_changes'    => ['Suivi des positions',       "Analyse des variations de positions dans Google."],
            'run_semantic_audit'        => ['Audit sémantique',          "Analyse du cocon sémantique et des gaps de contenu."],
            'publish_to_wordpress'      => ['Publication WordPress',     "Publication du contenu sur le site WordPress du projet."],
        ];

        $out = [];
        foreach ($plan as $step) {
            $tool = $step['tool'] ?? $step['name'] ?? 'unknown';
            $params = $step['parameters'] ?? $step['input'] ?? [];
            [$label, $description] = $labels[$tool] ?? [$tool, ''];

            $out[] = [
                'tool' => $tool,
                'label' => $label,
                'description' => $description,
                'parameters' => is_array($params) ? $params : [],
            ];
        }

        return $out;
    }

    private function getTaskTypeLabel(string $taskType): string
    {
        return match ($taskType) {
            'full_analysis'        => 'une analyse SEO complète',
            'content_strategy'     => 'une stratégie de contenu',
            'technical_audit'      => 'un audit technique',
            'weekly_audit'         => "l'audit hebdomadaire",
            'daily_position_check' => 'la vérification quotidienne des positions',
            'content_gap_alert'    => 'une alerte gaps de contenu',
            'monthly_report'       => 'le rapport mensuel',
            'rank_check'           => 'un suivi de positions',
            'content_publish'      => 'une publication de contenu',
            'semantic_audit'       => 'un audit sémantique',
            default                => sprintf('la tâche « %s »', $taskType),
        };
    }
}
