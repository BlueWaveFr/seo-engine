<?php

namespace SeoExpert\Engine\Service\Trait;

use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\User;
use SeoExpert\Engine\Service\ApiUsageService;

trait ApiUsageTrackerTrait
{
    protected ?ApiUsageService $apiUsageService = null;
    protected ?Company $currentCompany = null;
    protected ?User $currentUser = null;
    protected ?Project $currentProject = null;

    public function setApiUsageService(ApiUsageService $apiUsageService): void
    {
        $this->apiUsageService = $apiUsageService;
    }

    public function setTrackingContext(?Company $company = null, ?User $user = null, ?Project $project = null): void
    {
        $this->currentCompany = $company;
        $this->currentUser = $user;
        $this->currentProject = $project;
    }

    protected function trackApiCall(
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
        ?float $customCost = null,
    ): void {
        if ($this->apiUsageService === null) {
            return;
        }

        $this->apiUsageService->logApiCall(
            provider: $provider,
            endpoint: $endpoint,
            method: $method,
            statusCode: $statusCode,
            success: $success,
            errorMessage: $errorMessage,
            requestSizeBytes: $requestSizeBytes,
            responseSizeBytes: $responseSizeBytes,
            executionTimeMs: $executionTimeMs,
            metadata: $metadata,
            company: $this->currentCompany,
            user: $this->currentUser,
            project: $this->currentProject,
            customCost: $customCost,
        );
    }

    protected function trackWithTiming(
        string $provider,
        callable $apiCall,
        ?string $endpoint = null,
        string $method = 'GET',
        ?array $metadata = null,
        ?float $customCost = null,
    ): mixed {
        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;
        $result = null;

        try {
            $result = $apiCall();
            return $result;
        } catch (\Exception $e) {
            $success = false;
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->trackApiCall(
                provider: $provider,
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
    }
}
