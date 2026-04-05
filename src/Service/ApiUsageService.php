<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\ApiUsageLog;
use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\User;
use App\Repository\ApiUsageLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ApiUsageService
{
    // Cost estimates per request (in USD) - adjust based on your actual costs
    private const COST_ESTIMATES = [
        'google_oauth' => 0.0,
        'google_indexing' => 0.0,
        'google_search_console' => 0.0,
        'google_pagespeed' => 0.0,
        'google_safe_browsing' => 0.0,
        'google_custom_search' => 0.005, // $5 per 1000 queries
        'bing_webmaster' => 0.0,
        'bing_search' => 0.007, // ~$7 per 1000 queries
        'linkedin' => 0.0,
        'x' => 0.0,
        'ahrefs' => 0.01, // Varies by plan
        'moz' => 0.005,
        'semrush' => 0.01,
        'majestic' => 0.005,
        'anthropic' => 0.015, // Claude API - varies by model and tokens
        'screaming_frog' => 0.0,
        'commoncrawl' => 0.0,
        'wayback_machine' => 0.0,
        'ssl_labs' => 0.0,
        'w3c_validator' => 0.0,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiUsageLogRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Log an API call
     */
    public function logApiCall(
        string $provider,
        ?string $endpoint = null,
        string $method = 'GET',
        ?int $statusCode = null,
        bool $success = true,
        ?string $errorMessage = null,
        ?int $requestSizeBytes = null,
        ?int $responseSizeBytes = null,
        ?int $executionTimeMs = null,
        ?array $metadata = null,
        ?Company $company = null,
        ?User $user = null,
        ?Project $project = null,
        ?float $customCost = null,
    ): ApiUsageLog {
        $log = new ApiUsageLog();
        $log->setProvider($provider);
        $log->setEndpoint($endpoint);
        $log->setMethod($method);
        $log->setStatusCode($statusCode);
        $log->setSuccess($success);
        $log->setErrorMessage($errorMessage);
        $log->setRequestSizeBytes($requestSizeBytes);
        $log->setResponseSizeBytes($responseSizeBytes);
        $log->setExecutionTimeMs($executionTimeMs);
        $log->setMetadata($metadata);
        $log->setCompany($company);
        $log->setUser($user);
        $log->setProject($project);

        // Set cost estimate
        $cost = $customCost ?? $this->getCostEstimate($provider);
        $log->setCostEstimate((string) $cost);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        // Log errors for monitoring
        if (!$success) {
            $this->logger->warning('API call failed', [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'statusCode' => $statusCode,
                'error' => $errorMessage,
            ]);
        }

        return $log;
    }

    /**
     * Get cost estimate for a provider
     */
    public function getCostEstimate(string $provider): float
    {
        return self::COST_ESTIMATES[$provider] ?? 0.0;
    }

    /**
     * Get usage stats by provider for a date range
     */
    public function getStatsByProvider(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Company $company = null
    ): array {
        return $this->repository->getStatsByProvider($from, $to, $company);
    }

    /**
     * Get daily usage stats
     */
    public function getDailyStats(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $provider = null,
        ?Company $company = null
    ): array {
        return $this->repository->getDailyStats($from, $to, $provider, $company);
    }

    /**
     * Get total stats summary
     */
    public function getTotalStats(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?Company $company = null
    ): array {
        return $this->repository->getTotalStats($from, $to, $company);
    }

    /**
     * Get recent errors
     */
    public function getRecentErrors(int $limit = 10, ?Company $company = null): array
    {
        return $this->repository->getRecentErrors($limit, $company);
    }

    /**
     * Get stats by company
     */
    public function getStatsByCompany(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        return $this->repository->getStatsByCompany($from, $to);
    }

    /**
     * Get stats for today
     */
    public function getTodayStats(?Company $company = null): array
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = new \DateTimeImmutable('tomorrow');
        return $this->getTotalStats($today, $tomorrow, $company);
    }

    /**
     * Get stats for current month
     */
    public function getCurrentMonthStats(?Company $company = null): array
    {
        $firstDay = new \DateTimeImmutable('first day of this month midnight');
        $lastDay = new \DateTimeImmutable('last day of this month 23:59:59');
        return $this->getTotalStats($firstDay, $lastDay, $company);
    }

    /**
     * Get comprehensive dashboard stats
     */
    public function getDashboardStats(?Company $company = null): array
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $firstDayOfMonth = new \DateTimeImmutable('first day of this month midnight');
        $lastDayOfMonth = new \DateTimeImmutable('last day of this month 23:59:59');
        $last30Days = new \DateTimeImmutable('-30 days');

        return [
            'today' => $this->getTotalStats($today, $tomorrow, $company),
            'currentMonth' => $this->getTotalStats($firstDayOfMonth, $lastDayOfMonth, $company),
            'byProvider' => $this->getStatsByProvider($firstDayOfMonth, $lastDayOfMonth, $company),
            'dailyStats' => $this->getDailyStats($last30Days, $tomorrow, null, $company),
            'recentErrors' => $this->getRecentErrors(5, $company),
        ];
    }
}
