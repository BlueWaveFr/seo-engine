<?php

namespace SeoExpert\Engine\Service\Google;

use SeoExpert\Engine\Entity\ApiKey;
use SeoExpert\Engine\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client as GoogleClient;
use Google\Service\SearchConsole;
use Google\Service\Webmasters;
use Psr\Log\LoggerInterface;

class GoogleSearchConsoleService
{
    private GoogleClient $client;
    private string $effectiveClientId;
    private string $effectiveClientSecret;
    private string $effectiveRedirectUri;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        // Try to get credentials from database first
        $apiKeyRepo = $this->entityManager->getRepository(ApiKey::class);
        $googleOAuth = $apiKeyRepo->findOneBy(['provider' => ApiKey::PROVIDER_GOOGLE_OAUTH, 'isActive' => true]);

        if ($googleOAuth && $googleOAuth->getApiKey() && $googleOAuth->getApiSecret()) {
            $this->effectiveClientId = $googleOAuth->getApiKey();
            $this->effectiveClientSecret = $googleOAuth->getApiSecret();

            // Get redirect URI from additionalConfig or use env default
            $additionalConfig = $googleOAuth->getAdditionalConfig();
            $this->effectiveRedirectUri = $additionalConfig['redirect_uri'] ?? $this->redirectUri;

            $this->logger->info('Using Google OAuth credentials from database');
        } else {
            // Fallback to .env values
            $this->effectiveClientId = $this->clientId;
            $this->effectiveClientSecret = $this->clientSecret;
            $this->effectiveRedirectUri = $this->redirectUri;

            $this->logger->info('Using Google OAuth credentials from .env');
        }

        $this->client = new GoogleClient();
        $this->client->setClientId($this->effectiveClientId);
        $this->client->setClientSecret($this->effectiveClientSecret);
        $this->client->setRedirectUri($this->effectiveRedirectUri);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->addScope(Webmasters::WEBMASTERS_READONLY);
    }

    /**
     * Reinitialize the client (useful after credentials change in admin)
     */
    public function refreshCredentials(): void
    {
        $this->initializeClient();
    }

    /**
     * Get the OAuth authorization URL
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Failed to exchange code: ' . ($token['error_description'] ?? $token['error']));
        }

        return $token;
    }

    /**
     * Set access token from stored credentials
     */
    public function setAccessToken(array $token): void
    {
        $this->client->setAccessToken($token);

        // Refresh token if expired
        if ($this->client->isAccessTokenExpired()) {
            if ($this->client->getRefreshToken()) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                if (!isset($newToken['error'])) {
                    $this->client->setAccessToken($newToken);
                }
            }
        }
    }

    /**
     * Check if token needs refresh and return new token if refreshed
     */
    public function refreshTokenIfNeeded(array $token): ?array
    {
        $this->client->setAccessToken($token);

        if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            if (!isset($newToken['error'])) {
                return $newToken;
            }
        }

        return null;
    }

    /**
     * Get list of sites from Search Console
     */
    public function getSites(): array
    {
        $webmasters = new Webmasters($this->client);
        $sites = $webmasters->sites->listSites();

        return array_map(function ($site) {
            return [
                'siteUrl' => $site->getSiteUrl(),
                'permissionLevel' => $site->getPermissionLevel(),
            ];
        }, $sites->getSiteEntry() ?? []);
    }

    /**
     * Get search analytics data for a site
     */
    public function getSearchAnalytics(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions = ['query'],
        int $rowLimit = 100
    ): array {
        $webmasters = new Webmasters($this->client);

        $request = new \Google\Service\Webmasters\SearchAnalyticsQueryRequest();
        $request->setStartDate($startDate);
        $request->setEndDate($endDate);
        $request->setDimensions($dimensions);
        $request->setRowLimit($rowLimit);

        try {
            $response = $webmasters->searchanalytics->query($siteUrl, $request);

            return [
                'rows' => array_map(function ($row) use ($dimensions) {
                    $data = [
                        'clicks' => $row->getClicks(),
                        'impressions' => $row->getImpressions(),
                        'ctr' => $row->getCtr(),
                        'position' => $row->getPosition(),
                    ];

                    $keys = $row->getKeys();
                    foreach ($dimensions as $i => $dimension) {
                        $data[$dimension] = $keys[$i] ?? null;
                    }

                    return $data;
                }, $response->getRows() ?? []),
                'responseAggregationType' => $response->getResponseAggregationType(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Search Console API error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get performance summary for a site (last 28 days vs previous 28 days)
     */
    public function getPerformanceSummary(string $siteUrl): array
    {
        $endDate = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $startDate = (new \DateTimeImmutable('-28 days'))->format('Y-m-d');
        $prevEndDate = (new \DateTimeImmutable('-29 days'))->format('Y-m-d');
        $prevStartDate = (new \DateTimeImmutable('-56 days'))->format('Y-m-d');

        $webmasters = new Webmasters($this->client);

        // Current period - no dimensions to get totals
        $currentRequest = new \Google\Service\Webmasters\SearchAnalyticsQueryRequest();
        $currentRequest->setStartDate($startDate);
        $currentRequest->setEndDate($endDate);

        // Previous period
        $prevRequest = new \Google\Service\Webmasters\SearchAnalyticsQueryRequest();
        $prevRequest->setStartDate($prevStartDate);
        $prevRequest->setEndDate($prevEndDate);

        try {
            $currentResponse = $webmasters->searchanalytics->query($siteUrl, $currentRequest);
            $prevResponse = $webmasters->searchanalytics->query($siteUrl, $prevRequest);

            $currentRows = $currentResponse->getRows() ?? [];
            $prevRows = $prevResponse->getRows() ?? [];

            $current = [
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0,
                'position' => 0,
            ];

            $previous = [
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0,
                'position' => 0,
            ];

            if (!empty($currentRows)) {
                $row = $currentRows[0];
                $current = [
                    'clicks' => $row->getClicks(),
                    'impressions' => $row->getImpressions(),
                    'ctr' => $row->getCtr(),
                    'position' => $row->getPosition(),
                ];
            }

            if (!empty($prevRows)) {
                $row = $prevRows[0];
                $previous = [
                    'clicks' => $row->getClicks(),
                    'impressions' => $row->getImpressions(),
                    'ctr' => $row->getCtr(),
                    'position' => $row->getPosition(),
                ];
            }

            return [
                'current' => $current,
                'previous' => $previous,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'changes' => [
                    'clicks' => $this->calculateChange($previous['clicks'], $current['clicks']),
                    'impressions' => $this->calculateChange($previous['impressions'], $current['impressions']),
                    'ctr' => $this->calculateChange($previous['ctr'], $current['ctr']),
                    'position' => $this->calculateChange($previous['position'], $current['position'], true),
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Search Console API error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get top queries for a site
     */
    public function getTopQueries(string $siteUrl, int $limit = 50): array
    {
        $endDate = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $startDate = (new \DateTimeImmutable('-28 days'))->format('Y-m-d');

        return $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['query'], $limit);
    }

    /**
     * Get top pages for a site
     */
    public function getTopPages(string $siteUrl, int $limit = 50): array
    {
        $endDate = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $startDate = (new \DateTimeImmutable('-28 days'))->format('Y-m-d');

        return $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['page'], $limit);
    }

    /**
     * Get daily performance data for charts
     */
    public function getDailyPerformance(string $siteUrl, int $days = 28): array
    {
        $endDate = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $startDate = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');

        return $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['date'], 1000);
    }

    /**
     * Sync all Search Console data for a project
     */
    public function syncProjectData(string $siteUrl): array
    {
        $data = [];
        $errors = [];

        // Get performance summary
        try {
            $data['summary'] = $this->getPerformanceSummary($siteUrl);
        } catch (\Exception $e) {
            $errors['summary'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Search Console summary: ' . $e->getMessage());
        }

        // Get top queries
        try {
            $data['queries'] = $this->getTopQueries($siteUrl, 50);
        } catch (\Exception $e) {
            $errors['queries'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Search Console queries: ' . $e->getMessage());
        }

        // Get top pages
        try {
            $data['pages'] = $this->getTopPages($siteUrl, 50);
        } catch (\Exception $e) {
            $errors['pages'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Search Console pages: ' . $e->getMessage());
        }

        // Get daily performance
        try {
            $data['daily'] = $this->getDailyPerformance($siteUrl, 28);
        } catch (\Exception $e) {
            $errors['daily'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Search Console daily data: ' . $e->getMessage());
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'synced_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    private function calculateChange(float $previous, float $current, bool $lowerIsBetter = false): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        $change = (($current - $previous) / $previous) * 100;

        return round($change, 2);
    }
}
