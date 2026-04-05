<?php

namespace SeoExpert\Engine\Service\Cloudflare;

use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Service\ApiUsageService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class CloudflareService
{
    private const GRAPHQL_URL = 'https://api.cloudflare.com/client/v4/graphql';
    private const REST_URL = 'https://api.cloudflare.com/client/v4';
    private const PROVIDER_NAME = 'cloudflare';
    private const COST_PER_REQUEST = 0.0;

    private const BOT_SIGNATURES = [
        'Googlebot' => 'Googlebot',
        'Bingbot' => 'bingbot',
        'bingbot' => 'bingbot',
        'GPTBot' => 'GPTBot',
        'ClaudeBot' => 'ClaudeBot',
        'Claude-Web' => 'ClaudeBot',
        'Bytespider' => 'Bytespider',
        'Applebot' => 'Applebot',
        'YandexBot' => 'YandexBot',
        'Baiduspider' => 'Baiduspider',
        'DuckDuckBot' => 'DuckDuckBot',
        'Slurp' => 'Yahoo Slurp',
        'facebookexternalhit' => 'Facebook',
        'Twitterbot' => 'Twitter',
        'LinkedInBot' => 'LinkedIn',
        'SemrushBot' => 'SemrushBot',
        'AhrefsBot' => 'AhrefsBot',
        'MJ12bot' => 'Majestic',
        'DotBot' => 'DotBot',
        'PetalBot' => 'PetalBot',
        'CCBot' => 'CommonCrawl',
    ];

    private ?string $currentApiToken = null;
    private ?Company $currentCompany = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?ApiUsageService $apiUsageService = null,
    ) {}

    public function setApiTokenFromProject(?Project $project): void
    {
        if ($project) {
            $this->currentCompany = $project->getCompany();
            $this->currentApiToken = $project->getCloudflareApiToken();
        } else {
            $this->currentCompany = null;
            $this->currentApiToken = null;
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->currentApiToken);
    }

    /**
     * Verify the API token is valid
     */
    public function verifyToken(): array
    {
        return $this->makeRestRequest('GET', '/user/tokens/verify');
    }

    /**
     * List all zones accessible to this token
     */
    public function listZones(): array
    {
        $result = $this->makeRestRequest('GET', '/zones', ['per_page' => 50]);

        return array_map(fn(array $zone) => [
            'id' => $zone['id'],
            'name' => $zone['name'],
            'status' => $zone['status'],
            'plan' => $zone['plan']['name'] ?? null,
        ], $result['result'] ?? []);
    }

    /**
     * Get zone info (status, SSL, plan)
     */
    public function getZoneInfo(string $zoneId): array
    {
        $result = $this->makeRestRequest('GET', '/zones/' . $zoneId);
        $zone = $result['result'] ?? [];

        return [
            'id' => $zone['id'] ?? null,
            'name' => $zone['name'] ?? null,
            'status' => $zone['status'] ?? null,
            'paused' => $zone['paused'] ?? null,
            'plan' => $zone['plan']['name'] ?? null,
            'ssl_mode' => $zone['ssl']['mode'] ?? null,
            'development_mode' => $zone['development_mode'] ?? 0,
            'name_servers' => $zone['name_servers'] ?? [],
        ];
    }

    /**
     * Get bot/crawler analytics — crawl volume by bot, top crawled paths, response codes
     */
    public function getBotCrawlAnalytics(string $zoneId, string $startDate, string $endDate): array
    {
        $query = <<<'GRAPHQL'
        query BotCrawlAnalytics($zoneTag: String!, $start: Date!, $end: Date!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              httpRequestsAdaptiveGroups(
                filter: {
                  date_geq: $start
                  date_leq: $end
                  botScore_leq: 29
                }
                limit: 1000
                orderBy: [count_DESC]
              ) {
                count
                dimensions {
                  clientRequestPath
                  userAgent
                  edgeResponseStatus
                  date
                }
              }
            }
          }
        }
        GRAPHQL;

        $result = $this->makeGraphQLRequest($query, [
            'zoneTag' => $zoneId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $groups = $result['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [];

        return $this->parseBotAnalytics($groups);
    }

    /**
     * Get performance metrics — TTFB, cache hit ratio
     */
    public function getPerformanceMetrics(string $zoneId, string $startDate, string $endDate): array
    {
        $query = <<<'GRAPHQL'
        query PerformanceMetrics($zoneTag: String!, $start: Date!, $end: Date!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              httpRequestsAdaptiveGroups(
                filter: {
                  date_geq: $start
                  date_leq: $end
                }
                limit: 500
                orderBy: [count_DESC]
              ) {
                count
                avg {
                  edgeTimeToFirstByteMs
                }
                quantiles {
                  edgeTimeToFirstByteMsP50
                  edgeTimeToFirstByteMsP95
                  edgeTimeToFirstByteMsP99
                }
                dimensions {
                  cacheStatus
                  date
                }
              }
            }
          }
        }
        GRAPHQL;

        $result = $this->makeGraphQLRequest($query, [
            'zoneTag' => $zoneId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $groups = $result['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [];

        return $this->parsePerformanceMetrics($groups);
    }

    /**
     * Get HTTP error monitoring — 4xx/5xx rates, top error pages
     */
    public function getHttpErrors(string $zoneId, string $startDate, string $endDate): array
    {
        $query = <<<'GRAPHQL'
        query HttpErrors($zoneTag: String!, $start: Date!, $end: Date!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              httpRequestsAdaptiveGroups(
                filter: {
                  date_geq: $start
                  date_leq: $end
                  edgeResponseStatus_geq: 400
                }
                limit: 500
                orderBy: [count_DESC]
              ) {
                count
                dimensions {
                  edgeResponseStatus
                  clientRequestPath
                  date
                }
              }
            }
          }
        }
        GRAPHQL;

        $result = $this->makeGraphQLRequest($query, [
            'zoneTag' => $zoneId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $groups = $result['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [];

        return $this->parseHttpErrors($groups);
    }

    /**
     * Get bot security analytics — blocked crawlers, false positives
     */
    public function getBotSecurityAnalytics(string $zoneId, string $startDate, string $endDate): array
    {
        $query = <<<'GRAPHQL'
        query BotSecurity($zoneTag: String!, $start: Date!, $end: Date!) {
          viewer {
            zones(filter: {zoneTag: $zoneTag}) {
              httpRequestsAdaptiveGroups(
                filter: {
                  date_geq: $start
                  date_leq: $end
                  botScore_leq: 29
                  edgeResponseStatus_in: [403, 429, 503]
                }
                limit: 200
                orderBy: [count_DESC]
              ) {
                count
                dimensions {
                  userAgent
                  edgeResponseStatus
                  clientRequestPath
                  date
                }
              }
            }
          }
        }
        GRAPHQL;

        $result = $this->makeGraphQLRequest($query, [
            'zoneTag' => $zoneId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $groups = $result['data']['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [];

        $blockedBots = [];
        foreach ($groups as $group) {
            $ua = $group['dimensions']['userAgent'] ?? '';
            $botName = $this->identifyBot($ua);
            $status = $group['dimensions']['edgeResponseStatus'] ?? 0;
            $count = $group['count'] ?? 0;

            $key = $botName . '_' . $status;
            if (!isset($blockedBots[$key])) {
                $blockedBots[$key] = [
                    'bot' => $botName,
                    'status_code' => $status,
                    'blocked_requests' => 0,
                ];
            }
            $blockedBots[$key]['blocked_requests'] += $count;
        }

        usort($blockedBots, fn($a, $b) => $b['blocked_requests'] <=> $a['blocked_requests']);

        return [
            'blocked_bots' => array_values($blockedBots),
            'total_blocked' => array_sum(array_column($blockedBots, 'blocked_requests')),
        ];
    }

    /**
     * Sync all Cloudflare data for a project
     */
    public function syncProjectData(Project $project): array
    {
        $this->setApiTokenFromProject($project);

        if (!$this->isConfigured()) {
            throw new \RuntimeException('Cloudflare API token is not configured');
        }

        $zoneId = $project->getCloudflareZoneId();
        if (!$zoneId) {
            throw new \RuntimeException('Cloudflare Zone ID is not configured for this project');
        }

        $endDate = (new \DateTimeImmutable())->format('Y-m-d');
        $startDate = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        $data = [];
        $errors = [];

        // Zone info
        try {
            $data['zone_info'] = $this->getZoneInfo($zoneId);
        } catch (\Exception $e) {
            $errors['zone_info'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Cloudflare zone info: ' . $e->getMessage());
        }

        // Bot crawl analytics
        try {
            $data['bot_analytics'] = $this->getBotCrawlAnalytics($zoneId, $startDate, $endDate);
        } catch (\Exception $e) {
            $errors['bot_analytics'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Cloudflare bot analytics: ' . $e->getMessage());
        }

        // Performance metrics
        try {
            $data['performance'] = $this->getPerformanceMetrics($zoneId, $startDate, $endDate);
        } catch (\Exception $e) {
            $errors['performance'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Cloudflare performance: ' . $e->getMessage());
        }

        // HTTP errors
        try {
            $data['http_errors'] = $this->getHttpErrors($zoneId, $startDate, $endDate);
        } catch (\Exception $e) {
            $errors['http_errors'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Cloudflare HTTP errors: ' . $e->getMessage());
        }

        // Bot security
        try {
            $data['bot_security'] = $this->getBotSecurityAnalytics($zoneId, $startDate, $endDate);
        } catch (\Exception $e) {
            $errors['bot_security'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Cloudflare bot security: ' . $e->getMessage());
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'synced_at' => (new \DateTimeImmutable())->format('c'),
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    // --- Parsing helpers ---

    private function parseBotAnalytics(array $groups): array
    {
        $botVolumes = [];
        $topPaths = [];
        $statusCodes = [];
        $dailyCrawl = [];

        foreach ($groups as $group) {
            $ua = $group['dimensions']['userAgent'] ?? '';
            $path = $group['dimensions']['clientRequestPath'] ?? '/';
            $status = $group['dimensions']['edgeResponseStatus'] ?? 0;
            $date = $group['dimensions']['date'] ?? '';
            $count = $group['count'] ?? 0;

            $botName = $this->identifyBot($ua);

            // Bot volumes
            $botVolumes[$botName] = ($botVolumes[$botName] ?? 0) + $count;

            // Top paths
            $topPaths[$path] = ($topPaths[$path] ?? 0) + $count;

            // Status codes
            $statusCodes[$status] = ($statusCodes[$status] ?? 0) + $count;

            // Daily crawl
            if ($date) {
                $dailyCrawl[$date] = ($dailyCrawl[$date] ?? 0) + $count;
            }
        }

        arsort($botVolumes);
        arsort($topPaths);
        ksort($dailyCrawl);

        return [
            'bot_volumes' => array_map(
                fn($name, $count) => ['bot' => $name, 'requests' => $count],
                array_keys($botVolumes),
                array_values($botVolumes)
            ),
            'top_paths' => array_map(
                fn($path, $count) => ['path' => $path, 'requests' => $count],
                array_keys(array_slice($topPaths, 0, 20, true)),
                array_values(array_slice($topPaths, 0, 20, true))
            ),
            'status_codes' => array_map(
                fn($code, $count) => ['code' => $code, 'requests' => $count],
                array_keys($statusCodes),
                array_values($statusCodes)
            ),
            'daily_crawl' => array_map(
                fn($date, $count) => ['date' => $date, 'requests' => $count],
                array_keys($dailyCrawl),
                array_values($dailyCrawl)
            ),
            'total_crawl_requests' => array_sum($botVolumes),
        ];
    }

    private function parsePerformanceMetrics(array $groups): array
    {
        $cacheStats = [];
        $ttfbValues = [];
        $totalRequests = 0;

        foreach ($groups as $group) {
            $cacheStatus = $group['dimensions']['cacheStatus'] ?? 'unknown';
            $count = $group['count'] ?? 0;
            $totalRequests += $count;

            $cacheStats[$cacheStatus] = ($cacheStats[$cacheStatus] ?? 0) + $count;

            if (isset($group['avg']['edgeTimeToFirstByteMs'])) {
                $ttfbValues[] = [
                    'avg' => round($group['avg']['edgeTimeToFirstByteMs'], 1),
                    'p50' => round($group['quantiles']['edgeTimeToFirstByteMsP50'] ?? 0, 1),
                    'p95' => round($group['quantiles']['edgeTimeToFirstByteMsP95'] ?? 0, 1),
                    'p99' => round($group['quantiles']['edgeTimeToFirstByteMsP99'] ?? 0, 1),
                    'count' => $count,
                ];
            }
        }

        $hitCount = ($cacheStats['hit'] ?? 0) + ($cacheStats['stale'] ?? 0) + ($cacheStats['revalidated'] ?? 0);
        $cacheHitRatio = $totalRequests > 0 ? round(($hitCount / $totalRequests) * 100, 1) : 0;

        // Weighted average TTFB
        $weightedTtfb = ['avg' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0];
        $totalWeight = array_sum(array_column($ttfbValues, 'count'));
        if ($totalWeight > 0) {
            foreach ($ttfbValues as $ttfb) {
                $weight = $ttfb['count'] / $totalWeight;
                $weightedTtfb['avg'] += $ttfb['avg'] * $weight;
                $weightedTtfb['p50'] += $ttfb['p50'] * $weight;
                $weightedTtfb['p95'] += $ttfb['p95'] * $weight;
                $weightedTtfb['p99'] += $ttfb['p99'] * $weight;
            }
            $weightedTtfb = array_map(fn($v) => round($v, 1), $weightedTtfb);
        }

        return [
            'ttfb' => $weightedTtfb,
            'cache_hit_ratio' => $cacheHitRatio,
            'cache_breakdown' => array_map(
                fn($status, $count) => ['status' => $status, 'requests' => $count],
                array_keys($cacheStats),
                array_values($cacheStats)
            ),
            'total_requests' => $totalRequests,
        ];
    }

    private function parseHttpErrors(array $groups): array
    {
        $errorsByCode = [];
        $topErrorPages = [];
        $dailyErrors = [];

        foreach ($groups as $group) {
            $status = $group['dimensions']['edgeResponseStatus'] ?? 0;
            $path = $group['dimensions']['clientRequestPath'] ?? '/';
            $date = $group['dimensions']['date'] ?? '';
            $count = $group['count'] ?? 0;

            $category = $status >= 500 ? '5xx' : '4xx';
            $errorsByCode[$status] = ($errorsByCode[$status] ?? 0) + $count;
            $topErrorPages[$path] = ($topErrorPages[$path] ?? 0) + $count;

            if ($date) {
                if (!isset($dailyErrors[$date])) {
                    $dailyErrors[$date] = ['4xx' => 0, '5xx' => 0];
                }
                $dailyErrors[$date][$category] += $count;
            }
        }

        arsort($errorsByCode);
        arsort($topErrorPages);
        ksort($dailyErrors);

        return [
            'errors_by_code' => array_map(
                fn($code, $count) => ['code' => $code, 'requests' => $count],
                array_keys($errorsByCode),
                array_values($errorsByCode)
            ),
            'top_error_pages' => array_map(
                fn($path, $count) => ['path' => $path, 'errors' => $count],
                array_keys(array_slice($topErrorPages, 0, 20, true)),
                array_values(array_slice($topErrorPages, 0, 20, true))
            ),
            'daily_errors' => array_map(
                fn($date, $counts) => ['date' => $date, '4xx' => $counts['4xx'], '5xx' => $counts['5xx']],
                array_keys($dailyErrors),
                array_values($dailyErrors)
            ),
            'total_errors' => array_sum($errorsByCode),
        ];
    }

    private function identifyBot(string $userAgent): string
    {
        foreach (self::BOT_SIGNATURES as $signature => $name) {
            if (stripos($userAgent, $signature) !== false) {
                return $name;
            }
        }
        return 'Other Bot';
    }

    // --- HTTP request helpers ---

    private function makeGraphQLRequest(string $query, array $variables): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Cloudflare API token is not configured');
        }

        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;

        try {
            $response = $this->httpClient->request('POST', self::GRAPHQL_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->currentApiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $errorBody = $response->getContent(false);
                $success = false;
                $errorMessage = 'HTTP ' . $statusCode . ': ' . $errorBody;
                throw new \RuntimeException('Cloudflare GraphQL error: ' . $statusCode . ' - ' . $errorBody);
            }

            $data = $response->toArray();

            if (!empty($data['errors'])) {
                $errorMessage = $data['errors'][0]['message'] ?? 'Unknown GraphQL error';
                $success = false;
                throw new \RuntimeException('Cloudflare GraphQL error: ' . $errorMessage);
            }

            return $data;
        } catch (\Exception $e) {
            $success = false;
            $errorMessage = $errorMessage ?? $e->getMessage();
            $this->logger->error('Cloudflare GraphQL request failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->trackApiCall('graphql', 'POST', $statusCode, $success, $errorMessage, $executionTimeMs);
        }
    }

    private function makeRestRequest(string $method, string $endpoint, array $params = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Cloudflare API token is not configured');
        }

        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->currentApiToken,
                    'Content-Type' => 'application/json',
                ],
            ];

            if ($method === 'GET' && !empty($params)) {
                $options['query'] = $params;
            } elseif (!empty($params)) {
                $options['json'] = $params;
            }

            $response = $this->httpClient->request($method, self::REST_URL . $endpoint, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $errorBody = $response->getContent(false);
                $success = false;
                $errorMessage = 'HTTP ' . $statusCode . ': ' . $errorBody;
                throw new \RuntimeException('Cloudflare API error: ' . $statusCode . ' - ' . $errorBody);
            }

            return $response->toArray();
        } catch (\Exception $e) {
            $success = false;
            $errorMessage = $errorMessage ?? $e->getMessage();
            $this->logger->error('Cloudflare REST request failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->trackApiCall($endpoint, $method, $statusCode, $success, $errorMessage, $executionTimeMs);
        }
    }

    private function trackApiCall(
        string $endpoint,
        string $method,
        ?int $statusCode,
        bool $success,
        ?string $errorMessage,
        ?int $executionTimeMs,
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
            company: $this->currentCompany,
            customCost: self::COST_PER_REQUEST,
        );
    }
}
