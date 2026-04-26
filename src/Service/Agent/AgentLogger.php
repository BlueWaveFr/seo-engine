<?php

namespace SeoExpert\Engine\Service\Agent;

use SeoExpert\Engine\Entity\AgentLog;
use SeoExpert\Engine\Entity\AgentTask;
use SeoExpert\Engine\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AgentLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Log a tool execution within an agent task.
     *
     * @param AgentTask   $task     The parent task
     * @param Project     $project  The project context
     * @param string      $action   Tool name (e.g. "run_technical_audit")
     * @param array|null  $params   Input parameters sent to the tool
     * @param array|null  $result   Output returned by the tool
     * @param string      $status   "success" or "error"
     * @param int|null    $duration Execution time in milliseconds
     */
    public function logToolExecution(
        AgentTask $task,
        Project $project,
        string $action,
        ?array $params = null,
        ?array $result = null,
        string $status = 'success',
        ?int $duration = null,
    ): AgentLog {
        $log = new AgentLog();
        $log->setTask($task);
        $log->setProject($project);
        $log->setAction($action);
        $log->setParams($params);
        $log->setResult($result);
        $log->setStatus($status);
        $log->setDuration($duration);

        $task->addLog($log);

        $this->entityManager->persist($log);

        $this->logger->info('Agent tool executed', [
            'task_id' => $task->getId()->toRfc4122(),
            'action' => $action,
            'status' => $status,
            'duration_ms' => $duration,
        ]);

        return $log;
    }

    /**
     * Log an error that occurred during tool execution.
     */
    public function logToolError(
        AgentTask $task,
        Project $project,
        string $action,
        ?array $params,
        string $errorMessage,
        ?int $duration = null,
    ): AgentLog {
        return $this->logToolExecution(
            task: $task,
            project: $project,
            action: $action,
            params: $params,
            result: ['error' => $errorMessage],
            status: 'error',
            duration: $duration,
        );
    }
}
