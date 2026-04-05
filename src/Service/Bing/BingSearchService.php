<?php

namespace SeoExpert\Engine\Service\Bing;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bing Web Search API Service
 *
 * Documentation: https://learn.microsoft.com/en-us/bing/search-apis/bing-web-search/
 */
class BingSearchService
{
    private const API_BASE_URL = 'https://api.bing.microsoft.com/v7.0';

    private ?string $apiKey = null;
    private bool $isConfigured = false;
    private ?string $initError = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        try {
            $this->initializeClient();
        } catch (\Throwable $e) {
            $this->initError = $e->getMessage();
            $this->logger->error('BingSearchService init error: ' . $e->getMessage());
        }
    }

    private function initializeClient(): void
    {
        // Try to get API key from database first
        $apiKeyRepo = $this->entityManager->getRepository(ApiKey::class);
        $bingKey = $apiKeyRepo->findOneBy(['provider' => 'bing_search', 'isActive' => true]);

        if ($bingKey) {
            $this->apiKey = $bingKey->getApiKey();
            $this->isConfigured = true;
            $this->logger->info('Bing Search API configured from database');
            return;
        }

        // Fallback to environment variable
        $envKey = $_ENV['BING_SEARCH_API_KEY'] ?? null;
        if ($envKey) {
            $this->apiKey = $envKey;
            $this->isConfigured = true;
            $this->logger->info('Bing Search API configured from environment');
            return;
        }

        $this->logger->info('Bing Search API not configured');
    }

    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    /**
     * Perform a web search
     */
    public function search(string $query, array $options = []): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Search API not configured. Add API key in Admin > API Keys > Bing Search.',
            ];
        }

        try {
            $params = [
                'q' => $query,
                'count' => $options['count'] ?? 10,
                'offset' => $options['offset'] ?? 0,
                'mkt' => $options['market'] ?? 'fr-FR',
                'safeSearch' => $options['safeSearch'] ?? 'Moderate',
            ];

            // Add optional filters
            if (!empty($options['freshness'])) {
                $params['freshness'] = $options['freshness']; // Day, Week, Month
            }

            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/search', [
                'headers' => [
                    'Ocp-Apim-Subscription-Key' => $this->apiKey,
                ],
                'query' => $params,
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'query' => $query,
                'totalEstimatedMatches' => $data['webPages']['totalEstimatedMatches'] ?? 0,
                'results' => array_map(function ($item) {
                    return [
                        'name' => $item['name'] ?? '',
                        'url' => $item['url'] ?? '',
                        'displayUrl' => $item['displayUrl'] ?? '',
                        'snippet' => $item['snippet'] ?? '',
                        'dateLastCrawled' => $item['dateLastCrawled'] ?? null,
                    ];
                }, $data['webPages']['value'] ?? []),
                'relatedSearches' => array_map(function ($item) {
                    return $item['text'] ?? '';
                }, $data['relatedSearches']['value'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error("Bing search failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search for a specific site (site: operator)
     */
    public function searchSite(string $siteUrl, string $query = '', array $options = []): array
    {
        $fullQuery = "site:{$siteUrl}";
        if ($query) {
            $fullQuery .= " {$query}";
        }

        return $this->search($fullQuery, $options);
    }

    /**
     * Check how many pages are indexed for a domain
     */
    public function getIndexedPagesCount(string $domain): array
    {
        $result = $this->searchSite($domain, '', ['count' => 1]);

        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'domain' => $domain,
            'indexedPages' => $result['totalEstimatedMatches'],
        ];
    }

    /**
     * Get ranking position for a keyword
     */
    public function getRankingPosition(string $domain, string $keyword, int $maxResults = 100): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Search API not configured',
            ];
        }

        try {
            $position = null;
            $foundUrl = null;
            $offset = 0;
            $perPage = 50;

            while ($offset < $maxResults) {
                $result = $this->search($keyword, [
                    'count' => min($perPage, $maxResults - $offset),
                    'offset' => $offset,
                ]);

                if (!$result['success']) {
                    return $result;
                }

                foreach ($result['results'] as $index => $item) {
                    $itemDomain = parse_url($item['url'], PHP_URL_HOST);
                    $itemDomain = preg_replace('/^www\./', '', $itemDomain);
                    $searchDomain = preg_replace('/^www\./', '', $domain);

                    if (stripos($itemDomain, $searchDomain) !== false) {
                        $position = $offset + $index + 1;
                        $foundUrl = $item['url'];
                        break 2;
                    }
                }

                if (count($result['results']) < $perPage) {
                    break;
                }

                $offset += $perPage;
            }

            return [
                'success' => true,
                'keyword' => $keyword,
                'domain' => $domain,
                'position' => $position,
                'url' => $foundUrl,
                'searchedResults' => min($offset + $perPage, $maxResults),
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get Bing ranking: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get SERP competitors for a keyword
     */
    public function getCompetitors(string $keyword, int $count = 10): array
    {
        $result = $this->search($keyword, ['count' => $count]);

        if (!$result['success']) {
            return $result;
        }

        $competitors = [];
        foreach ($result['results'] as $index => $item) {
            $domain = parse_url($item['url'], PHP_URL_HOST);
            $domain = preg_replace('/^www\./', '', $domain);

            $competitors[] = [
                'position' => $index + 1,
                'domain' => $domain,
                'url' => $item['url'],
                'title' => $item['name'],
                'snippet' => $item['snippet'],
            ];
        }

        return [
            'success' => true,
            'keyword' => $keyword,
            'competitors' => $competitors,
        ];
    }

    /**
     * Image search
     */
    public function searchImages(string $query, array $options = []): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Search API not configured',
            ];
        }

        try {
            $params = [
                'q' => $query,
                'count' => $options['count'] ?? 10,
                'offset' => $options['offset'] ?? 0,
                'mkt' => $options['market'] ?? 'fr-FR',
                'safeSearch' => $options['safeSearch'] ?? 'Moderate',
            ];

            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/images/search', [
                'headers' => [
                    'Ocp-Apim-Subscription-Key' => $this->apiKey,
                ],
                'query' => $params,
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'query' => $query,
                'totalEstimatedMatches' => $data['totalEstimatedMatches'] ?? 0,
                'results' => array_map(function ($item) {
                    return [
                        'name' => $item['name'] ?? '',
                        'thumbnailUrl' => $item['thumbnailUrl'] ?? '',
                        'contentUrl' => $item['contentUrl'] ?? '',
                        'hostPageUrl' => $item['hostPageUrl'] ?? '',
                        'width' => $item['width'] ?? null,
                        'height' => $item['height'] ?? null,
                    ];
                }, $data['value'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error("Bing image search failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * News search
     */
    public function searchNews(string $query, array $options = []): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Search API not configured',
            ];
        }

        try {
            $params = [
                'q' => $query,
                'count' => $options['count'] ?? 10,
                'offset' => $options['offset'] ?? 0,
                'mkt' => $options['market'] ?? 'fr-FR',
                'safeSearch' => $options['safeSearch'] ?? 'Moderate',
            ];

            if (!empty($options['freshness'])) {
                $params['freshness'] = $options['freshness'];
            }

            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/news/search', [
                'headers' => [
                    'Ocp-Apim-Subscription-Key' => $this->apiKey,
                ],
                'query' => $params,
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'query' => $query,
                'totalEstimatedMatches' => $data['totalEstimatedMatches'] ?? 0,
                'results' => array_map(function ($item) {
                    return [
                        'name' => $item['name'] ?? '',
                        'url' => $item['url'] ?? '',
                        'description' => $item['description'] ?? '',
                        'datePublished' => $item['datePublished'] ?? null,
                        'provider' => $item['provider'][0]['name'] ?? null,
                        'image' => $item['image']['thumbnail']['contentUrl'] ?? null,
                    ];
                }, $data['value'] ?? []),
            ];
        } catch (\Exception $e) {
            $this->logger->error("Bing news search failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
