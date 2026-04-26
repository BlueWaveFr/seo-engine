<?php

declare(strict_types=1);

namespace SeoExpert\Engine\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SeoExpert\Engine\Entity\AgentTask;
use SeoExpert\Engine\Message\ExecuteAgentTaskMessage;
use SeoExpert\Engine\Service\Agent\AgentOrchestrator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Async handler for {@see ExecuteAgentTaskMessage}.
 *
 * Loads the AgentTask, runs {@see AgentOrchestrator::executeTask()},
 * and ensures the task always reaches a terminal status (completed/failed)
 * even if the orchestrator throws.
 *
 * Re-entrant safe: relies on the framework-provided EntityManager, which is
 * reset between message dispatches when running through `messenger:consume`
 * thanks to DoctrineCloseConnectionMiddleware / EntityManagerCleanerListener.
 */
#[AsMessageHandler]
final class ExecuteAgentTaskMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentOrchestrator $orchestrator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteAgentTaskMessage $message): void
    {
        $taskId = $message->getTaskId();

        try {
            $uuid = Uuid::fromString($taskId);
        } catch (\Throwable $e) {
            $this->logger->error('ExecuteAgentTaskMessage: invalid task UUID', [
                'task_id' => $taskId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        /** @var AgentTask|null $task */
        $task = $this->entityManager->getRepository(AgentTask::class)->find($uuid);
        if ($task === null) {
            $this->logger->warning('ExecuteAgentTaskMessage: task not found, skipping', [
                'task_id' => $taskId,
            ]);
            return;
        }

        $status = $task->getStatus();
        if ($status !== AgentTask::STATUS_APPROVED) {
            // Idempotent — already executed, failed, rejected, or otherwise not eligible.
            $this->logger->warning('ExecuteAgentTaskMessage: task not in "approved" status, skipping', [
                'task_id' => $taskId,
                'status'  => $status,
            ]);
            return;
        }

        $start = microtime(true);
        $this->logger->info('ExecuteAgentTaskMessage: starting execution', [
            'task_id'   => $taskId,
            'task_type' => $task->getTaskType(),
        ]);

        try {
            $task = $this->orchestrator->executeTask($task);

            $this->logger->info('ExecuteAgentTaskMessage: execution finished', [
                'task_id'     => $taskId,
                'status'      => $task->getStatus(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ExecuteAgentTaskMessage: execution threw, marking task failed', [
                'task_id'     => $taskId,
                'error'       => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            // Defensive: ensure the task does not stay stuck in "executing".
            try {
                // Re-fetch in case the EM was closed by the exception path.
                if (!$this->entityManager->isOpen()) {
                    // The EM is closed; we cannot persist anything reliably here.
                    // The messenger worker will be restarted (or the next dispatch
                    // will get a fresh EM); rethrow so the message goes to retry/failed.
                    throw $e;
                }

                $fresh = $this->entityManager->getRepository(AgentTask::class)->find($uuid);
                if ($fresh !== null && $fresh->getStatus() !== AgentTask::STATUS_COMPLETED
                    && $fresh->getStatus() !== AgentTask::STATUS_FAILED
                ) {
                    $fresh->markAsFailed([
                        'error' => $e->getMessage(),
                    ]);
                    $this->entityManager->flush();
                }
            } catch (\Throwable $inner) {
                $this->logger->error('ExecuteAgentTaskMessage: failed to persist failure state', [
                    'task_id' => $taskId,
                    'error'   => $inner->getMessage(),
                ]);
            }
        }
    }
}
