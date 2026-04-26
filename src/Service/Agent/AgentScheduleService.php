<?php

declare(strict_types=1);

namespace SeoExpert\Engine\Service\Agent;

use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use SeoExpert\Engine\Entity\AgentSchedule;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\User;

/**
 * Helper service for managing {@see AgentSchedule} entities.
 *
 * Responsible for CRUD-level operations, cron expression validation and
 * next-run computation. The actual execution lives in the console command
 * `app:agent:run-schedules`, which relies on {@see self::getDueSchedules()}.
 */
class AgentScheduleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create a new schedule, validating the cron expression and computing
     * the first `nextRunAt`.
     *
     * @param array<string, mixed> $config
     */
    public function createSchedule(
        Project $project,
        User $user,
        string $taskType,
        string $cronExpression,
        array $config = [],
    ): AgentSchedule {
        $this->validateCronExpression($cronExpression);

        $schedule = new AgentSchedule();
        $schedule->setProject($project);
        $schedule->setUser($user);
        $schedule->setTaskType($taskType);
        $schedule->setCronExpression($cronExpression);
        $schedule->setConfig($config);
        $schedule->setIsActive(true);
        $schedule->setNextRunAt($this->computeNextRun($cronExpression));

        $this->entityManager->persist($schedule);
        $this->entityManager->flush();

        return $schedule;
    }

    /**
     * Update a schedule's mutable fields. Any parameter left as `null` is
     * preserved. The `nextRunAt` is recomputed whenever the cron expression
     * changes.
     *
     * @param array<string, mixed>|null $config
     */
    public function updateSchedule(
        AgentSchedule $schedule,
        ?string $taskType = null,
        ?string $cronExpression = null,
        ?array $config = null,
        ?bool $isActive = null,
    ): AgentSchedule {
        if ($taskType !== null) {
            $schedule->setTaskType($taskType);
        }

        if ($cronExpression !== null && $cronExpression !== $schedule->getCronExpression()) {
            $this->validateCronExpression($cronExpression);
            $schedule->setCronExpression($cronExpression);
            $schedule->setNextRunAt($this->computeNextRun($cronExpression));
        }

        if ($config !== null) {
            $schedule->setConfig($config);
        }

        if ($isActive !== null) {
            $schedule->setIsActive($isActive);
        }

        $this->entityManager->flush();

        return $schedule;
    }

    public function toggleSchedule(AgentSchedule $schedule, bool $active): AgentSchedule
    {
        $schedule->setIsActive($active);

        if ($active && $schedule->getNextRunAt() === null) {
            $schedule->setNextRunAt($this->computeNextRun($schedule->getCronExpression()));
        }

        $this->entityManager->flush();

        return $schedule;
    }

    public function deleteSchedule(AgentSchedule $schedule): void
    {
        $this->entityManager->remove($schedule);
        $this->entityManager->flush();
    }

    /**
     * Return all active schedules whose `nextRunAt` is due (or null).
     *
     * @return AgentSchedule[]
     */
    public function getDueSchedules(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->entityManager->getRepository(AgentSchedule::class)
            ->createQueryBuilder('s')
            ->where('s.isActive = :active')
            ->andWhere('s.nextRunAt IS NULL OR s.nextRunAt <= :now')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('s.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compute the next firing time for a cron expression.
     *
     * @throws \InvalidArgumentException When the expression is invalid.
     */
    public function computeNextRun(
        string $cronExpression,
        ?\DateTimeImmutable $fromDate = null,
    ): \DateTimeImmutable {
        $this->validateCronExpression($cronExpression);

        $cron = new CronExpression($cronExpression);
        $from = $fromDate ?? new \DateTimeImmutable();

        $next = $cron->getNextRunDate($from);

        return \DateTimeImmutable::createFromMutable($next);
    }

    /**
     * Validate a cron expression. Returns true on success, throws otherwise.
     *
     * @throws \InvalidArgumentException
     */
    public function validateCronExpression(string $cronExpression): bool
    {
        if (trim($cronExpression) === '') {
            throw new \InvalidArgumentException('Cron expression cannot be empty.');
        }

        if (!CronExpression::isValidExpression($cronExpression)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid cron expression: "%s". Expected 5 space-separated fields (minute hour day-of-month month day-of-week) or a supported alias (@hourly, @daily, @weekly, @monthly, @yearly).',
                $cronExpression,
            ));
        }

        return true;
    }
}
