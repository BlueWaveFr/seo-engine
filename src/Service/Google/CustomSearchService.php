<?php

namespace SeoExpert\Engine\Service\Google;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CustomSearchService
{
    private const API_URL = 'https://www.googleapis.com/customsearch/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $searchEngineId, // CX ID
    ) {}

    /**
     * Get estimated number of indexed pages for a domain
     */
    public function getIndexedPagesCount(string $domain): array
    {
        // Clean domain
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        // Remove www. for cleaner search (Google indexes both)
        $searchDomain = preg_replace('/^www\./', '', $domain);

        try {
            // Check if API is configured
            if (empty($this->apiKey) || empty($this->searchEngineId)) {
                return [
                    'domain' => $domain,
                    'indexed_pages' => null,
                    'error' => 'API Google Custom Search non configuree. Verifiez GOOGLE_CUSTOM_SEARCH_API_KEY et GOOGLE_SEARCH_ENGINE_ID dans .env',
                    'checked_at' => (new \DateTimeImmutable())->format('c'),
                ];
            }

            // Use site: operator to find indexed pages
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->searchEngineId,
                    'q' => "site:{$searchDomain}",
                    'num' => 1, // We only need the count, not results
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->error('Custom Search API returned non-200 status', [
                    'domain' => $domain,
                    'status' => $statusCode,
                ]);

                return [
                    'domain' => $domain,
                    'indexed_pages' => null,
                    'error' => "Erreur API Google (HTTP {$statusCode})",
                    'checked_at' => (new \DateTimeImmutable())->format('c'),
                ];
            }

            $data = $response->toArray();

            // Check for API errors in response
            if (isset($data['error'])) {
                $errorMessage = $data['error']['message'] ?? 'Erreur inconnue';
                $this->logger->error('Custom Search API error response', [
                    'domain' => $domain,
                    'error' => $errorMessage,
                ]);

                return [
                    'domain' => $domain,
                    'indexed_pages' => null,
                    'error' => $errorMessage,
                    'checked_at' => (new \DateTimeImmutable())->format('c'),
                ];
            }

            $totalResults = (int) ($data['searchInformation']['totalResults'] ?? 0);
            $searchTime = (float) ($data['searchInformation']['searchTime'] ?? 0);

            // Log for debugging
            $this->logger->info('Custom Search API result', [
                'domain' => $domain,
                'search_domain' => $searchDomain,
                'total_results' => $totalResults,
                'raw_response' => $data['searchInformation'] ?? null,
            ]);

            return [
                'domain' => $domain,
                'indexed_pages' => $totalResults,
                'search_time' => $searchTime,
                'formatted_total' => $data['searchInformation']['formattedTotalResults'] ?? (string) $totalResults,
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            // 4xx errors (bad request, unauthorized, etc.)
            $this->logger->error('Custom Search API client error: ' . $e->getMessage(), [
                'domain' => $domain,
            ]);

            // Try to get more details from response
            $errorMessage = 'Erreur de configuration API';
            if (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), 'blocked')) {
                $errorMessage = 'API Custom Search non activee. Activez-la sur console.cloud.google.com/apis/library';
            } elseif (str_contains($e->getMessage(), '401')) {
                $errorMessage = 'Cle API invalide ou expiree';
            }

            return [
                'domain' => $domain,
                'indexed_pages' => null,
                'error' => $errorMessage,
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Custom Search API error: ' . $e->getMessage(), [
                'domain' => $domain,
            ]);

            return [
                'domain' => $domain,
                'indexed_pages' => null,
                'error' => $e->getMessage(),
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        }
    }

    /**
     * Get sample of indexed pages for a domain
     * @param string $domain Domain to search
     * @param int $count Number of results (max 10)
     * @param int $start Starting position (1-indexed, max 91)
     */
    public function getIndexedPagesSample(string $domain, int $count = 10, int $start = 1): array
    {
        // Clean domain
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        // Remove www. for cleaner search
        $searchDomain = preg_replace('/^www\./', '', $domain);

        // API limits: start must be between 1-91, num between 1-10
        $start = max(1, min($start, 91));
        $count = max(1, min($count, 10));

        try {
            // Check if API is configured
            if (empty($this->apiKey) || empty($this->searchEngineId)) {
                return [
                    'domain' => $domain,
                    'total_indexed' => null,
                    'pages' => [],
                    'error' => 'API Google Custom Search non configuree',
                    'checked_at' => (new \DateTimeImmutable())->format('c'),
                ];
            }

            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->searchEngineId,
                    'q' => "site:{$searchDomain}",
                    'num' => $count,
                    'start' => $start,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            $pages = [];
            $position = $start;
            foreach ($data['items'] ?? [] as $item) {
                $pages[] = [
                    'position' => $position,
                    'url' => $item['link'] ?? null,
                    'title' => $item['title'] ?? null,
                    'snippet' => $item['snippet'] ?? null,
                    'cached_url' => $item['cacheId'] ?? null,
                ];
                $position++;
            }

            $totalResults = (int) ($data['searchInformation']['totalResults'] ?? 0);

            return [
                'domain' => $domain,
                'total_indexed' => $totalResults,
                'start' => $start,
                'count' => count($pages),
                'has_more' => ($start + count($pages) - 1) < min($totalResults, 100), // API limite à 100 résultats
                'pages' => $pages,
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Custom Search API error: ' . $e->getMessage(), [
                'domain' => $domain,
            ]);

            return [
                'domain' => $domain,
                'total_indexed' => null,
                'pages' => [],
                'error' => $e->getMessage(),
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        }
    }

    /**
     * Check if a specific URL is indexed
     */
    public function isUrlIndexed(string $url): array
    {
        try {
            // Search for exact URL using info: operator alternative
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->searchEngineId,
                    'q' => "\"" . $url . "\"", // Exact match search
                    'num' => 1,
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            $totalResults = (int) ($data['searchInformation']['totalResults'] ?? 0);
            $foundUrl = null;

            // Check if the URL is in results
            foreach ($data['items'] ?? [] as $item) {
                if (($item['link'] ?? '') === $url || str_contains($item['link'] ?? '', $url)) {
                    $foundUrl = $item['link'];
                    break;
                }
            }

            return [
                'url' => $url,
                'indexed' => $totalResults > 0 && $foundUrl !== null,
                'found_url' => $foundUrl,
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Custom Search API error: ' . $e->getMessage(), [
                'url' => $url,
            ]);

            return [
                'url' => $url,
                'indexed' => null,
                'error' => $e->getMessage(),
                'checked_at' => (new \DateTimeImmutable())->format('c'),
            ];
        }
    }

    /**
     * Compare indexation between two domains
     */
    public function compareIndexation(string $domain1, string $domain2): array
    {
        $result1 = $this->getIndexedPagesCount($domain1);
        $result2 = $this->getIndexedPagesCount($domain2);

        $pages1 = $result1['indexed_pages'] ?? 0;
        $pages2 = $result2['indexed_pages'] ?? 0;

        $difference = $pages1 - $pages2;
        $ratio = $pages2 > 0 ? round($pages1 / $pages2, 2) : null;

        return [
            'domain1' => $result1,
            'domain2' => $result2,
            'comparison' => [
                'difference' => $difference,
                'ratio' => $ratio,
                'leader' => $pages1 > $pages2 ? $domain1 : ($pages2 > $pages1 ? $domain2 : 'equal'),
            ],
            'compared_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
