<?php

declare(strict_types=1);

namespace SeoExpert\Engine\Message;

/**
 * Dispatch this message to execute an approved AgentTask asynchronously.
 *
 * The handler ({@see \SeoExpert\Engine\MessageHandler\ExecuteAgentTaskMessageHandler})
 * loads the task by id, runs it via {@see \SeoExpert\Engine\Service\Agent\AgentOrchestrator::executeTask()},
 * and persists the resulting status (executing -> completed/failed).
 */
final readonly class ExecuteAgentTaskMessage
{
    public function __construct(
        private string $taskId,
    ) {
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }
}
