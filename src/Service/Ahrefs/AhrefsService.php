<?php

namespace SeoExpert\Engine\Service\Ahrefs;

use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Service\ApiUsageService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AhrefsService
{
    private const API_URL = 'https://api.ahrefs.com/v3';
    private const PROVIDER_NAME = 'ahrefs';
    private const COST_PER_REQUEST = 0.01; // Adjust based on your Ahrefs plan

    private ?string $currentApiKey = null;
    private ?Company $currentCompany = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly ?ApiUsageService $apiUsageService = null,
    ) {
        $this->currentApiKey = $this->apiKey;
    }

    /**
     * Set API key from company settings (takes precedence over env config)
     */
    public function setApiKeyFromCompany(?Company $company): void
    {
        $this->currentCompany = $company;
        if ($company && $company->hasAhrefsApiKey()) {
            $this->currentApiKey = $company->getAhrefsApiKey();
        } else {
            $this->currentApiKey = $this->apiKey;
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->currentApiKey);
    }

    public function isConfiguredForCompany(?Company $company): bool
    {
        if ($company && $company->hasAhrefsApiKey()) {
            return true;
        }
        return !empty($this->apiKey);
    }

    /**
     * Get a recent date for API requests (Ahrefs data is typically available with a few days delay)
     * We use 3 days ago to ensure data availability
     */
    private function getRecentDate(): string
    {
        return (new \DateTimeImmutable('-3 days'))->format('Y-m-d');
    }

    /**
     * Get domain rating
     * Endpoint: /site-explorer/domain-rating
     */
    public function getDomainRating(string $domain): array
    {
        return $this->makeRequest('/site-explorer/domain-rating', [
            'target' => $domain,
            'mode' => 'domain',
            'date' => $this->getRecentDate(),
        ]);
    }

    /**
     * Get domain metrics (backlinks stats)
     * Endpoint: /site-explorer/metrics
     */
    public function getMetrics(string $domain): array
    {
        return $this->makeRequest('/site-explorer/metrics', [
            'target' => $domain,
            'mode' => 'domain',
            'date' => $this->getRecentDate(),
            'select' => 'org_traffic,org_keywords,backlinks,refdomains',
        ]);
    }

    /**
     * Get organic keywords for a domain
     * Endpoint: /site-explorer/organic-keywords
     *
     * Available columns in API v3: keyword, volume, best_position, sum_traffic, keyword_difficulty
     * Note: 'url' column is NOT available for this endpoint
     */
    public function getOrganicKeywords(string $domain, int $limit = 100, string $country = 'fr'): array
    {
        return $this->makeRequest('/site-explorer/organic-keywords', [
            'target' => $domain,
            'mode' => 'domain',
            'country' => $country,
            'limit' => $limit,
            'date' => $this->getRecentDate(),
            'select' => 'keyword,volume,best_position,sum_traffic,keyword_difficulty',
            'order_by' => 'volume:desc',
        ]);
    }

    /**
     * Get all backlinks for a domain
     * Endpoint: /site-explorer/all-backlinks
     */
    public function getBacklinks(string $domain, int $limit = 100): array
    {
        return $this->makeRequest('/site-explorer/all-backlinks', [
            'target' => $domain,
            'mode' => 'domain',
            'limit' => $limit,
            'select' => 'url_from,url_to,domain_rating_source,anchor,first_seen',
            'order_by' => 'domain_rating_source:desc',
        ]);
    }

    /**
     * Get referring domains
     * Endpoint: /site-explorer/refdomains
     */
    public function getReferringDomains(string $domain, int $limit = 100): array
    {
        return $this->makeRequest('/site-explorer/refdomains', [
            'target' => $domain,
            'mode' => 'domain',
            'limit' => $limit,
            'select' => 'domain,domain_rating,backlinks,first_seen',
            'order_by' => 'domain_rating:desc',
        ]);
    }

    /**
     * Get keyword metrics
     * Endpoint: /keywords-explorer/metrics
     */
    public function getKeywordMetrics(array $keywords, string $country = 'fr'): array
    {
        return $this->makeRequest('/keywords-explorer/metrics', [
            'keywords' => $keywords,
            'country' => $country,
            'select' => 'keyword,volume,difficulty,cpc',
        ], 'POST');
    }

    /**
     * Get keyword ideas (related keywords)
     * Endpoint: /keywords-explorer/matching-terms
     */
    public function getKeywordIdeas(string $keyword, string $country = 'fr', int $limit = 100): array
    {
        return $this->makeRequest('/keywords-explorer/matching-terms', [
            'keyword' => $keyword,
            'country' => $country,
            'limit' => $limit,
            'select' => 'keyword,volume,difficulty,cpc',
            'order_by' => 'volume:desc',
        ]);
    }

    /**
     * Get top pages for a domain
     * Endpoint: /site-explorer/top-pages
     *
     * Available columns in API v3: url, sum_traffic, keywords, top_keyword
     */
    public function getTopPages(string $domain, int $limit = 100, string $country = 'fr'): array
    {
        return $this->makeRequest('/site-explorer/top-pages', [
            'target' => $domain,
            'mode' => 'domain',
            'country' => $country,
            'limit' => $limit,
            'date' => $this->getRecentDate(),
            'select' => 'url,sum_traffic,keywords,top_keyword',
            'order_by' => 'sum_traffic:desc',
        ]);
    }

    /**
     * Fetch and store Ahrefs data for a project
     */
    public function syncProjectData(Project $project): array
    {
        // Use company's API key if available
        $this->setApiKeyFromCompany($project->getCompany());

        if (!$this->isConfigured()) {
            throw new \RuntimeException('Ahrefs API key is not configured');
        }

        $websiteUrl = $project->getWebsiteUrl();
        if (!$websiteUrl) {
            throw new \RuntimeException('Project has no website URL configured');
        }

        // Extract domain from URL
        $domain = parse_url($websiteUrl, PHP_URL_HOST) ?: $websiteUrl;
        $domain = preg_replace('/^www\./', '', $domain);

        $data = [];
        $errors = [];

        // Fetch domain rating
        try {
            $domainRating = $this->getDomainRating($domain);
            // API returns {domain_rating: X, ahrefs_rank: Y} directly
            $data['overview'] = [
                'domain_rating' => is_array($domainRating['domain_rating'] ?? null)
                    ? ($domainRating['domain_rating']['domain_rating'] ?? null)
                    : ($domainRating['domain_rating'] ?? null),
                'ahrefs_rank' => is_array($domainRating['ahrefs_rank'] ?? null)
                    ? ($domainRating['ahrefs_rank']['ahrefs_rank'] ?? null)
                    : ($domainRating['ahrefs_rank'] ?? null),
            ];
        } catch (\Exception $e) {
            $errors['domain_rating'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Ahrefs domain rating: ' . $e->getMessage());
        }

        // Fetch metrics (backlinks, organic traffic, etc.)
        try {
            $metrics = $this->getMetrics($domain);
            // API returns {metrics: {org_traffic: X, ...}} - extract from metrics key if present
            $metricsData = $metrics['metrics'] ?? $metrics;
            if (isset($data['overview'])) {
                $data['overview']['organic_traffic'] = $metricsData['org_traffic'] ?? null;
                $data['overview']['organic_keywords'] = $metricsData['org_keywords'] ?? null;
                $data['overview']['backlinks'] = $metricsData['backlinks'] ?? null;
                $data['overview']['referring_domains'] = $metricsData['refdomains'] ?? null;
            } else {
                $data['overview'] = [
                    'organic_traffic' => $metricsData['org_traffic'] ?? null,
                    'organic_keywords' => $metricsData['org_keywords'] ?? null,
                    'backlinks' => $metricsData['backlinks'] ?? null,
                    'referring_domains' => $metricsData['refdomains'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            $errors['metrics'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Ahrefs metrics: ' . $e->getMessage());
        }

        // Fetch organic keywords
        try {
            $country = strtolower(substr($project->getTargetCountry(), 0, 2));
            $keywordsData = $this->getOrganicKeywords($domain, 50, $country);
            // Normalize column names from API v3 (best_position -> position, sum_traffic -> traffic, keyword_difficulty -> difficulty)
            $keywords = array_map(function ($kw) {
                return [
                    'keyword' => $kw['keyword'] ?? '',
                    'volume' => $kw['volume'] ?? 0,
                    'position' => $kw['best_position'] ?? null,
                    'traffic' => $kw['sum_traffic'] ?? 0,
                    'difficulty' => $kw['keyword_difficulty'] ?? null,
                ];
            }, $keywordsData['keywords'] ?? []);
            $data['organic_keywords'] = [
                'keywords' => $keywords,
            ];
        } catch (\Exception $e) {
            $errors['organic_keywords'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Ahrefs organic keywords: ' . $e->getMessage());
        }

        // Fetch top pages
        try {
            $country = strtolower(substr($project->getTargetCountry(), 0, 2));
            $topPagesData = $this->getTopPages($domain, 20, $country);
            // Normalize column names from API v3 (sum_traffic -> traffic)
            $pages = array_map(function ($page) {
                return [
                    'url' => $page['url'] ?? '',
                    'traffic' => $page['sum_traffic'] ?? 0,
                    'keywords' => $page['keywords'] ?? 0,
                    'top_keyword' => $page['top_keyword'] ?? '',
                ];
            }, $topPagesData['pages'] ?? []);
            $data['top_pages'] = [
                'pages' => $pages,
            ];
        } catch (\Exception $e) {
            $errors['top_pages'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Ahrefs top pages: ' . $e->getMessage());
        }

        // Fetch backlinks
        try {
            $backlinksData = $this->getBacklinks($domain, 20);
            $data['backlinks'] = [
                'backlinks' => $backlinksData['backlinks'] ?? [],
            ];
        } catch (\Exception $e) {
            $errors['backlinks'] = $e->getMessage();
            $this->logger->warning('Failed to fetch Ahrefs backlinks: ' . $e->getMessage());
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'synced_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    private function makeRequest(string $endpoint, array $params, string $method = 'GET'): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Ahrefs API key is not configured');
        }

        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;

        try {
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->currentApiKey,
                ],
            ];

            if ($method === 'GET') {
                $options['query'] = $params;
            } else {
                $options['json'] = $params;
            }

            $response = $this->httpClient->request($method, self::API_URL . $endpoint, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $errorBody = $response->getContent(false);
                $this->logger->error('Ahrefs API error', [
                    'status' => $statusCode,
                    'response' => $errorBody,
                    'endpoint' => $endpoint,
                ]);
                $success = false;
                $errorMessage = 'HTTP ' . $statusCode . ': ' . $errorBody;
                throw new \RuntimeException('Ahrefs API error: ' . $statusCode . ' - ' . $errorBody);
            }

            return $response->toArray();
        } catch (\Exception $e) {
            $success = false;
            $errorMessage = $e->getMessage();
            $this->logger->error('Ahrefs API request failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->trackApiCall($endpoint, $method, $statusCode, $success, $errorMessage, $executionTimeMs, $params);
        }
    }

    private function trackApiCall(
        string $endpoint,
        string $method,
        ?int $statusCode,
        bool $success,
        ?string $errorMessage,
        ?int $executionTimeMs,
        ?array $params = null,
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
            metadata: $params,
            company: $this->currentCompany,
            customCost: self::COST_PER_REQUEST,
        );
    }
}
