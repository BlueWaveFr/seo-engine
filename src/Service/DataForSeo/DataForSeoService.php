<?php

namespace SeoExpert\Engine\Service\DataForSeo;

use SeoExpert\Engine\Entity\ApiKey;
use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\User;
use App\Repository\ApiKeyRepository;
use SeoExpert\Engine\Service\ApiUsageService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class DataForSeoService
{
    private const API_URL = 'https://api.dataforseo.com/v3';
    private const PROVIDER_NAME = 'dataforseo';

    // Costs per request type (approximate)
    private const COST_KEYWORDS_DATA = 0.001;
    private const COST_SERP_LIVE = 0.002;
    private const COST_RELATED_KEYWORDS = 0.001;
    private const COST_KEYWORD_SUGGESTIONS = 0.001;
    private const COST_BACKLINKS_SUMMARY = 0.002;
    private const COST_BACKLINKS_LIST = 0.0001; // Per row
    private const COST_LLM_MENTIONS = 0.101; // Base + per row

    private ?string $currentLogin = null;
    private ?string $currentPassword = null;
    private ?Company $currentCompany = null;
    private ?User $currentUser = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly ?ApiUsageService $apiUsageService = null,
        private readonly ?string $login = null,
        private readonly ?string $password = null,
    ) {
        $this->loadCredentials();
    }

    /**
     * Load credentials from DB or env
     */
    private function loadCredentials(): void
    {
        // First try to load from database
        $apiKey = $this->apiKeyRepository->findOneBy([
            'provider' => ApiKey::PROVIDER_DATAFORSEO,
            'isActive' => true,
        ]);

        if ($apiKey && $apiKey->getApiKey() && $apiKey->getApiSecret()) {
            $this->currentLogin = $apiKey->getApiKey();
            $this->currentPassword = $apiKey->getApiSecret();
            return;
        }

        // Fallback to environment variables
        $this->currentLogin = $this->login;
        $this->currentPassword = $this->password;
    }

    /**
     * Set credentials from company settings
     */
    public function setCredentialsFromCompany(?Company $company): void
    {
        $this->currentCompany = $company;
        // Only override if company has specific credentials
        if ($company && $company->getDataForSeoLogin() && $company->getDataForSeoPassword()) {
            $this->currentLogin = $company->getDataForSeoLogin();
            $this->currentPassword = $company->getDataForSeoPassword();
        }
        // Otherwise keep credentials loaded from DB or env (don't reset them)
    }

    public function setUser(?User $user): void
    {
        $this->currentUser = $user;
    }

    public function isConfigured(): bool
    {
        return !empty($this->currentLogin) && !empty($this->currentPassword);
    }

    /**
     * Get keyword search volume, CPC, competition, and difficulty
     *
     * @param array $keywords List of keywords to analyze
     * @param string $locationCode Location code (e.g., 2250 for France)
     * @param string $languageCode Language code (e.g., 'fr')
     * @return array
     */
    public function getKeywordsData(array $keywords, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $payload = [
            [
                'keywords' => array_slice($keywords, 0, 1000), // Max 1000 keywords per request
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
            ]
        ];

        $response = $this->makeRequest('/keywords_data/google_ads/search_volume/live', $payload, self::COST_KEYWORDS_DATA);

        return $this->parseKeywordsDataResponse($response);
    }

    /**
     * Get keyword suggestions based on a seed keyword
     *
     * @param string $keyword Seed keyword
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @param int $limit Max results
     * @return array
     */
    public function getKeywordSuggestions(string $keyword, string $locationCode = '2250', string $languageCode = 'fr', int $limit = 100): array
    {
        $payload = [
            [
                'keywords' => [$keyword],
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
                'include_seed_keyword' => true,
                'limit' => $limit,
            ]
        ];

        $response = $this->makeRequest('/keywords_data/google_ads/keywords_for_keywords/live', $payload, self::COST_KEYWORD_SUGGESTIONS);

        return $this->parseKeywordSuggestionsResponse($response);
    }

    /**
     * Get related keywords (semantic variations)
     *
     * @param string $keyword Seed keyword
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @param int $limit Max results
     * @return array
     */
    public function getRelatedKeywords(string $keyword, string $locationCode = '2250', string $languageCode = 'fr', int $limit = 100): array
    {
        $payload = [
            [
                'keyword' => $keyword,
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
                'depth' => 2,
                'limit' => $limit,
            ]
        ];

        $response = $this->makeRequest('/dataforseo_labs/google/related_keywords/live', $payload, self::COST_RELATED_KEYWORDS);

        return $this->parseRelatedKeywordsResponse($response);
    }

    /**
     * Get SERP analysis for a keyword (who ranks, SERP features, etc.)
     *
     * @param string $keyword Keyword to analyze
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @return array
     */
    public function getSerpAnalysis(string $keyword, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $payload = [
            [
                'keyword' => $keyword,
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
                'device' => 'desktop',
                'os' => 'windows',
                'depth' => 10,
            ]
        ];

        $response = $this->makeRequest('/serp/google/organic/live/regular', $payload, self::COST_SERP_LIVE);

        return $this->parseSerpResponse($response);
    }

    /**
     * Get People Also Ask questions for a keyword
     *
     * @param string $keyword Keyword to analyze
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @return array
     */
    public function getPeopleAlsoAsk(string $keyword, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $payload = [
            [
                'keyword' => $keyword,
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
                'device' => 'desktop',
                'os' => 'windows',
                'depth' => 100,
            ]
        ];

        $response = $this->makeRequest('/serp/google/organic/live/regular', $payload, self::COST_SERP_LIVE);

        return $this->parsePeopleAlsoAskResponse($response);
    }

    /**
     * Get keyword difficulty score
     *
     * @param array $keywords Keywords to analyze
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @return array
     */
    public function getKeywordDifficulty(array $keywords, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $payload = [
            [
                'keywords' => array_slice($keywords, 0, 1000),
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
            ]
        ];

        $response = $this->makeRequest('/dataforseo_labs/google/bulk_keyword_difficulty/live', $payload, self::COST_KEYWORDS_DATA);

        return $this->parseKeywordDifficultyResponse($response);
    }

    /**
     * Full keyword research combining multiple data sources
     *
     * @param string $keyword Seed keyword
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @return array
     */
    public function fullKeywordResearch(string $keyword, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $results = [
            'keyword' => $keyword,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'data' => [],
            'errors' => [],
        ];

        // Get main keyword data
        try {
            $keywordData = $this->getKeywordsData([$keyword], $locationCode, $languageCode);
            $results['data']['main_keyword'] = $keywordData[0] ?? null;
        } catch (\Exception $e) {
            $results['errors']['main_keyword'] = $e->getMessage();
            $this->logger->warning('Failed to get keyword data: ' . $e->getMessage());
        }

        // Get suggestions
        try {
            $suggestions = $this->getKeywordSuggestions($keyword, $locationCode, $languageCode, 50);
            $results['data']['suggestions'] = $suggestions;
        } catch (\Exception $e) {
            $results['errors']['suggestions'] = $e->getMessage();
            $this->logger->warning('Failed to get keyword suggestions: ' . $e->getMessage());
        }

        // Get related keywords
        try {
            $related = $this->getRelatedKeywords($keyword, $locationCode, $languageCode, 30);
            $results['data']['related'] = $related;
        } catch (\Exception $e) {
            $results['errors']['related'] = $e->getMessage();
            $this->logger->warning('Failed to get related keywords: ' . $e->getMessage());
        }

        // Get SERP analysis
        try {
            $serp = $this->getSerpAnalysis($keyword, $locationCode, $languageCode);
            $results['data']['serp'] = $serp;
        } catch (\Exception $e) {
            $results['errors']['serp'] = $e->getMessage();
            $this->logger->warning('Failed to get SERP analysis: ' . $e->getMessage());
        }

        // Get People Also Ask
        try {
            $paa = $this->getPeopleAlsoAsk($keyword, $locationCode, $languageCode);
            $results['data']['people_also_ask'] = $paa;
        } catch (\Exception $e) {
            $results['errors']['people_also_ask'] = $e->getMessage();
            $this->logger->warning('Failed to get PAA: ' . $e->getMessage());
        }

        return $results;
    }

    // ==================== BACKLINKS API ====================

    /**
     * Get backlinks summary for a domain
     *
     * @param string $target Domain or URL to analyze
     * @return array
     */
    public function getBacklinksSummary(string $target): array
    {
        $payload = [
            [
                'target' => $target,
            ]
        ];

        $response = $this->makeRequest('/backlinks/summary/live', $payload, self::COST_BACKLINKS_SUMMARY);

        return $this->parseBacklinksSummaryResponse($response);
    }

    /**
     * Get list of backlinks for a domain
     *
     * @param string $target Domain or URL to analyze
     * @param int $limit Max results
     * @param int $offset Offset for pagination
     * @param string $mode Mode: as_is, one_per_domain, one_per_anchor
     * @return array
     */
    public function getBacklinks(string $target, int $limit = 100, int $offset = 0, string $mode = 'as_is'): array
    {
        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'offset' => $offset,
                'mode' => $mode,
                'order_by' => ['rank,desc'],
            ]
        ];

        $response = $this->makeRequest('/backlinks/backlinks/live', $payload, self::COST_BACKLINKS_LIST * $limit);

        return $this->parseBacklinksResponse($response);
    }

    /**
     * Get referring domains for a target
     *
     * @param string $target Domain or URL to analyze
     * @param int $limit Max results
     * @param int $offset Offset for pagination
     * @return array
     */
    public function getReferringDomains(string $target, int $limit = 100, int $offset = 0): array
    {
        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'offset' => $offset,
                'order_by' => ['rank,desc'],
            ]
        ];

        $response = $this->makeRequest('/backlinks/referring_domains/live', $payload, self::COST_BACKLINKS_LIST * $limit);

        return $this->parseReferringDomainsResponse($response);
    }

    /**
     * Get anchors analysis for a target
     *
     * @param string $target Domain or URL to analyze
     * @param int $limit Max results
     * @return array
     */
    public function getAnchors(string $target, int $limit = 100): array
    {
        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'order_by' => ['backlinks,desc'],
            ]
        ];

        $response = $this->makeRequest('/backlinks/anchors/live', $payload, self::COST_BACKLINKS_LIST * $limit);

        return $this->parseAnchorsResponse($response);
    }

    /**
     * Get new and lost backlinks
     *
     * @param string $target Domain or URL to analyze
     * @param int $limit Max results
     * @param string $type Type: new, lost, or all
     * @return array
     */
    public function getNewLostBacklinks(string $target, int $limit = 100, string $type = 'all'): array
    {
        $filters = [];
        if ($type === 'new') {
            $filters = [['item_type', '=', 'new']];
        } elseif ($type === 'lost') {
            $filters = [['item_type', '=', 'lost']];
        }

        $payload = [
            [
                'target' => $target,
                'limit' => $limit,
                'order_by' => ['first_seen,desc'],
            ]
        ];

        if (!empty($filters)) {
            $payload[0]['filters'] = $filters;
        }

        $response = $this->makeRequest('/backlinks/history/live', $payload, self::COST_BACKLINKS_LIST * $limit);

        return $this->parseBacklinksHistoryResponse($response);
    }

    // ==================== LLM MENTIONS API ====================

    /**
     * Get top domains mentioned in AI responses for keywords
     *
     * @param array $keywords Keywords to analyze (max 10)
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @param int $limit Max results
     * @return array
     */
    public function getLlmMentionsTopDomains(array $keywords, string $locationCode = '2250', string $languageCode = 'fr', int $limit = 20): array
    {
        $target = array_map(fn($kw) => ['keyword' => $kw], array_slice($keywords, 0, 10));

        $payload = [
            [
                'target' => $target,
                'platform_type' => 'google',
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
                'limit' => $limit,
            ]
        ];

        $response = $this->makeRequest('/ai_optimization/llm_mentions/top_domains/live', $payload, self::COST_LLM_MENTIONS);

        return $this->parseLlmMentionsTopDomainsResponse($response);
    }

    /**
     * Get aggregated LLM mentions metrics
     *
     * @param array $keywords Keywords to analyze
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @return array
     */
    public function getLlmMentionsMetrics(array $keywords, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $target = array_map(fn($kw) => ['keyword' => $kw], array_slice($keywords, 0, 10));

        $payload = [
            [
                'target' => $target,
                'platform_type' => 'google',
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
            ]
        ];

        $response = $this->makeRequest('/ai_optimization/llm_mentions/aggregated_metrics/live', $payload, self::COST_LLM_MENTIONS);

        return $this->parseLlmMentionsMetricsResponse($response);
    }

    /**
     * Get cross-aggregated metrics to compare multiple domains/brands
     *
     * @param array $domains Domains to compare
     * @param string $locationCode Location code
     * @param string $languageCode Language code
     * @return array
     */
    public function getLlmMentionsComparison(array $domains, string $locationCode = '2250', string $languageCode = 'fr'): array
    {
        $target = array_map(fn($domain) => ['domain' => $domain], array_slice($domains, 0, 10));

        $payload = [
            [
                'target' => $target,
                'platform_type' => 'google',
                'location_code' => (int) $locationCode,
                'language_code' => $languageCode,
            ]
        ];

        $response = $this->makeRequest('/ai_optimization/llm_mentions/cross_aggregated_metrics/live', $payload, self::COST_LLM_MENTIONS);

        return $this->parseLlmMentionsComparisonResponse($response);
    }

    /**
     * Parse keywords data response
     */
    private function parseKeywordsDataResponse(array $response): array
    {
        $keywords = [];
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'] ?? [];
            foreach ($result as $item) {
                $keywords[] = [
                    'keyword' => $item['keyword'] ?? '',
                    'search_volume' => $item['search_volume'] ?? 0,
                    'cpc' => $item['cpc'] ?? 0,
                    'competition' => $item['competition'] ?? 0,
                    'competition_level' => $item['competition_level'] ?? 'UNKNOWN',
                    'monthly_searches' => $item['monthly_searches'] ?? [],
                ];
            }
        }

        return $keywords;
    }

    /**
     * Parse keyword suggestions response
     */
    private function parseKeywordSuggestionsResponse(array $response): array
    {
        $suggestions = [];
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'] ?? [];
            foreach ($result as $item) {
                $suggestions[] = [
                    'keyword' => $item['keyword'] ?? '',
                    'search_volume' => $item['search_volume'] ?? 0,
                    'cpc' => $item['cpc'] ?? 0,
                    'competition' => $item['competition'] ?? 0,
                    'competition_level' => $item['competition_level'] ?? 'UNKNOWN',
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Parse related keywords response
     */
    private function parseRelatedKeywordsResponse(array $response): array
    {
        $related = [];
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'] ?? [];
            foreach ($result as $item) {
                $seedKeywordData = $item['seed_keyword_data'] ?? null;
                $relatedKeywords = $item['items'] ?? [];

                foreach ($relatedKeywords as $kw) {
                    $keywordData = $kw['keyword_data'] ?? [];
                    $related[] = [
                        'keyword' => $keywordData['keyword'] ?? '',
                        'search_volume' => $keywordData['keyword_info']['search_volume'] ?? 0,
                        'cpc' => $keywordData['keyword_info']['cpc'] ?? 0,
                        'competition' => $keywordData['keyword_info']['competition'] ?? 0,
                        'difficulty' => $keywordData['keyword_properties']['keyword_difficulty'] ?? null,
                        'search_intent' => $keywordData['search_intent_info']['main_intent'] ?? null,
                    ];
                }
            }
        }

        return $related;
    }

    /**
     * Parse SERP response
     */
    private function parseSerpResponse(array $response): array
    {
        $serp = [
            'organic_results' => [],
            'serp_features' => [],
            'total_results' => 0,
        ];

        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'] ?? [];
            foreach ($result as $item) {
                $serp['total_results'] = $item['se_results_count'] ?? 0;
                $items = $item['items'] ?? [];

                foreach ($items as $serpItem) {
                    $type = $serpItem['type'] ?? '';

                    if ($type === 'organic') {
                        $serp['organic_results'][] = [
                            'position' => $serpItem['rank_absolute'] ?? 0,
                            'title' => $serpItem['title'] ?? '',
                            'url' => $serpItem['url'] ?? '',
                            'domain' => $serpItem['domain'] ?? '',
                            'description' => $serpItem['description'] ?? '',
                        ];
                    } elseif (!in_array($type, ['organic', 'paid'])) {
                        // Track SERP features
                        if (!in_array($type, $serp['serp_features'])) {
                            $serp['serp_features'][] = $type;
                        }
                    }
                }
            }
        }

        return $serp;
    }

    /**
     * Parse People Also Ask response
     */
    private function parsePeopleAlsoAskResponse(array $response): array
    {
        $questions = [];
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'] ?? [];
            foreach ($result as $item) {
                $items = $item['items'] ?? [];

                foreach ($items as $serpItem) {
                    if (($serpItem['type'] ?? '') === 'people_also_ask') {
                        $paaItems = $serpItem['items'] ?? [];
                        foreach ($paaItems as $paa) {
                            $questions[] = [
                                'question' => $paa['title'] ?? '',
                                'url' => $paa['url'] ?? '',
                                'domain' => $paa['domain'] ?? '',
                            ];
                        }
                    }
                }
            }
        }

        return $questions;
    }

    /**
     * Parse keyword difficulty response
     */
    private function parseKeywordDifficultyResponse(array $response): array
    {
        $difficulties = [];
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'] ?? [];
            foreach ($result as $item) {
                $difficulties[$item['keyword'] ?? ''] = [
                    'keyword' => $item['keyword'] ?? '',
                    'difficulty' => $item['keyword_difficulty'] ?? 0,
                ];
            }
        }

        return $difficulties;
    }

    // ==================== BACKLINKS PARSING ====================

    /**
     * Parse backlinks summary response
     */
    private function parseBacklinksSummaryResponse(array $response): array
    {
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'][0] ?? null;
            if ($result) {
                return [
                    'target' => $result['target'] ?? '',
                    'backlinks' => $result['backlinks'] ?? 0,
                    'referring_domains' => $result['referring_domains'] ?? 0,
                    'referring_main_domains' => $result['referring_main_domains'] ?? 0,
                    'referring_ips' => $result['referring_ips'] ?? 0,
                    'referring_subnets' => $result['referring_subnets'] ?? 0,
                    'rank' => $result['rank'] ?? 0,
                    'broken_backlinks' => $result['broken_backlinks'] ?? 0,
                    'broken_pages' => $result['broken_pages'] ?? 0,
                    'referring_links_tld' => $result['referring_links_tld'] ?? [],
                    'referring_links_types' => $result['referring_links_types'] ?? [],
                    'referring_links_attributes' => $result['referring_links_attributes'] ?? [],
                    'referring_links_platform_types' => $result['referring_links_platform_types'] ?? [],
                ];
            }
        }

        return [];
    }

    /**
     * Parse backlinks list response
     */
    private function parseBacklinksResponse(array $response): array
    {
        $backlinks = [];
        $tasks = $response['tasks'] ?? [];
        $totalCount = 0;

        foreach ($tasks as $task) {
            $totalCount = $task['result'][0]['total_count'] ?? 0;
            $items = $task['result'][0]['items'] ?? [];

            foreach ($items as $item) {
                $backlinks[] = [
                    'url_from' => $item['url_from'] ?? '',
                    'url_to' => $item['url_to'] ?? '',
                    'domain_from' => $item['domain_from'] ?? '',
                    'domain_to' => $item['domain_to'] ?? '',
                    'anchor' => $item['anchor'] ?? '',
                    'rank' => $item['rank'] ?? 0,
                    'page_from_rank' => $item['page_from_rank'] ?? 0,
                    'domain_from_rank' => $item['domain_from_rank'] ?? 0,
                    'is_new' => $item['is_new'] ?? false,
                    'is_lost' => $item['is_lost'] ?? false,
                    'is_dofollow' => ($item['dofollow'] ?? false),
                    'first_seen' => $item['first_seen'] ?? null,
                    'last_seen' => $item['last_seen'] ?? null,
                    'link_type' => $item['type'] ?? '',
                    'link_attribute' => $item['link_attribute'] ?? [],
                ];
            }
        }

        return [
            'total_count' => $totalCount,
            'items' => $backlinks,
        ];
    }

    /**
     * Parse referring domains response
     */
    private function parseReferringDomainsResponse(array $response): array
    {
        $domains = [];
        $tasks = $response['tasks'] ?? [];
        $totalCount = 0;

        foreach ($tasks as $task) {
            $totalCount = $task['result'][0]['total_count'] ?? 0;
            $items = $task['result'][0]['items'] ?? [];

            foreach ($items as $item) {
                $domains[] = [
                    'domain' => $item['domain'] ?? '',
                    'rank' => $item['rank'] ?? 0,
                    'backlinks' => $item['backlinks'] ?? 0,
                    'first_seen' => $item['first_seen'] ?? null,
                    'lost_date' => $item['lost_date'] ?? null,
                    'backlinks_spam_score' => $item['backlinks_spam_score'] ?? 0,
                    'broken_backlinks' => $item['broken_backlinks'] ?? 0,
                    'referring_pages' => $item['referring_pages'] ?? 0,
                    'country' => $item['country'] ?? '',
                ];
            }
        }

        return [
            'total_count' => $totalCount,
            'items' => $domains,
        ];
    }

    /**
     * Parse anchors response
     */
    private function parseAnchorsResponse(array $response): array
    {
        $anchors = [];
        $tasks = $response['tasks'] ?? [];
        $totalCount = 0;

        foreach ($tasks as $task) {
            $totalCount = $task['result'][0]['total_count'] ?? 0;
            $items = $task['result'][0]['items'] ?? [];

            foreach ($items as $item) {
                $anchors[] = [
                    'anchor' => $item['anchor'] ?? '',
                    'backlinks' => $item['backlinks'] ?? 0,
                    'referring_domains' => $item['referring_domains'] ?? 0,
                    'referring_main_domains' => $item['referring_main_domains'] ?? 0,
                    'first_seen' => $item['first_seen'] ?? null,
                    'last_seen' => $item['last_seen'] ?? null,
                    'rank' => $item['rank'] ?? 0,
                ];
            }
        }

        return [
            'total_count' => $totalCount,
            'items' => $anchors,
        ];
    }

    /**
     * Parse backlinks history response
     */
    private function parseBacklinksHistoryResponse(array $response): array
    {
        $history = [];
        $tasks = $response['tasks'] ?? [];
        $totalCount = 0;

        foreach ($tasks as $task) {
            $totalCount = $task['result'][0]['total_count'] ?? 0;
            $items = $task['result'][0]['items'] ?? [];

            foreach ($items as $item) {
                $history[] = [
                    'date' => $item['date'] ?? '',
                    'new_backlinks' => $item['new_referring_domains'] ?? 0,
                    'lost_backlinks' => $item['lost_referring_domains'] ?? 0,
                    'new_referring_domains' => $item['new_referring_domains'] ?? 0,
                    'lost_referring_domains' => $item['lost_referring_domains'] ?? 0,
                    'cumulative' => [
                        'backlinks' => $item['cumulative']['backlinks'] ?? 0,
                        'referring_domains' => $item['cumulative']['referring_domains'] ?? 0,
                    ],
                ];
            }
        }

        return [
            'total_count' => $totalCount,
            'items' => $history,
        ];
    }

    // ==================== LLM MENTIONS PARSING ====================

    /**
     * Parse LLM mentions top domains response
     */
    private function parseLlmMentionsTopDomainsResponse(array $response): array
    {
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'][0] ?? null;
            if ($result) {
                $total = $result['total'] ?? [];
                $items = $result['items'] ?? [];

                $topDomains = [];
                foreach ($items as $item) {
                    $topDomains[] = [
                        'domain' => $item['key'] ?? '',
                        'mentions' => $this->extractMentionsMetric($item['platform'] ?? []),
                        'ai_search_volume' => $this->extractAiSearchVolume($item['platform'] ?? []),
                        'impressions' => $this->extractImpressions($item['platform'] ?? []),
                        'platforms' => $item['platform'] ?? [],
                    ];
                }

                return [
                    'total' => [
                        'mentions' => $this->sumMentions($total['platform'] ?? []),
                        'ai_search_volume' => $this->sumAiSearchVolume($total['platform'] ?? []),
                        'top_sources' => $total['sources_domain'] ?? [],
                    ],
                    'domains' => $topDomains,
                ];
            }
        }

        return ['total' => [], 'domains' => []];
    }

    /**
     * Parse LLM mentions metrics response
     */
    private function parseLlmMentionsMetricsResponse(array $response): array
    {
        $tasks = $response['tasks'] ?? [];

        foreach ($tasks as $task) {
            $result = $task['result'][0] ?? null;
            if ($result) {
                return [
                    'total_mentions' => $result['total_mentions'] ?? 0,
                    'total_impressions' => $result['total_impressions'] ?? 0,
                    'ai_search_volume' => $result['ai_search_volume'] ?? 0,
                    'platforms' => $result['platforms'] ?? [],
                    'top_keywords' => $result['top_keywords'] ?? [],
                ];
            }
        }

        return [];
    }

    /**
     * Parse LLM mentions comparison response
     */
    private function parseLlmMentionsComparisonResponse(array $response): array
    {
        $tasks = $response['tasks'] ?? [];
        $comparison = [];

        foreach ($tasks as $task) {
            $items = $task['result'] ?? [];
            foreach ($items as $item) {
                $comparison[] = [
                    'domain' => $item['target'] ?? '',
                    'mentions' => $item['mentions'] ?? 0,
                    'impressions' => $item['impressions'] ?? 0,
                    'ai_search_volume' => $item['ai_search_volume'] ?? 0,
                    'visibility_score' => $item['visibility_score'] ?? 0,
                ];
            }
        }

        return $comparison;
    }

    /**
     * Helper: Extract mentions from platform data
     */
    private function extractMentionsMetric(array $platformData): int
    {
        $total = 0;
        foreach ($platformData as $platform) {
            $total += $platform['mentions'] ?? 0;
        }
        return $total;
    }

    /**
     * Helper: Extract AI search volume from platform data
     */
    private function extractAiSearchVolume(array $platformData): int
    {
        $total = 0;
        foreach ($platformData as $platform) {
            $total += $platform['ai_search_volume'] ?? 0;
        }
        return $total;
    }

    /**
     * Helper: Extract impressions from platform data
     */
    private function extractImpressions(array $platformData): int
    {
        $total = 0;
        foreach ($platformData as $platform) {
            $total += $platform['impressions'] ?? 0;
        }
        return $total;
    }

    /**
     * Helper: Sum mentions from array
     */
    private function sumMentions(array $data): int
    {
        $total = 0;
        foreach ($data as $item) {
            $total += $item['mentions'] ?? 0;
        }
        return $total;
    }

    /**
     * Helper: Sum AI search volume from array
     */
    private function sumAiSearchVolume(array $data): int
    {
        $total = 0;
        foreach ($data as $item) {
            $total += $item['ai_search_volume'] ?? 0;
        }
        return $total;
    }

    /**
     * Make API request
     */
    private function makeRequest(string $endpoint, array $payload, float $estimatedCost): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('DataForSEO credentials are not configured');
        }

        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;
        $statusCode = null;

        try {
            $response = $this->httpClient->request('POST', self::API_URL . $endpoint, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->currentLogin . ':' . $this->currentPassword),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $errorBody = $response->getContent(false);
                $success = false;
                $errorMessage = 'HTTP ' . $statusCode . ': ' . $errorBody;
                $this->logger->error('DataForSEO API error', [
                    'status' => $statusCode,
                    'response' => $errorBody,
                    'endpoint' => $endpoint,
                ]);
                throw new \RuntimeException('DataForSEO API error: ' . $statusCode);
            }

            $data = $response->toArray();

            // Check for API-level errors
            if (isset($data['status_code']) && $data['status_code'] !== 20000) {
                $success = false;
                $errorMessage = $data['status_message'] ?? 'Unknown API error';
                throw new \RuntimeException('DataForSEO API error: ' . $errorMessage);
            }

            return $data;
        } catch (\Exception $e) {
            $success = false;
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            $executionTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->trackApiCall($endpoint, $statusCode, $success, $errorMessage, $executionTimeMs, $estimatedCost);
        }
    }

    /**
     * Track API usage
     */
    private function trackApiCall(
        string $endpoint,
        ?int $statusCode,
        bool $success,
        ?string $errorMessage,
        ?int $executionTimeMs,
        float $estimatedCost,
    ): void {
        if ($this->apiUsageService === null) {
            return;
        }

        $this->apiUsageService->logApiCall(
            provider: self::PROVIDER_NAME,
            endpoint: $endpoint,
            method: 'POST',
            statusCode: $statusCode,
            success: $success,
            errorMessage: $errorMessage,
            executionTimeMs: $executionTimeMs,
            metadata: null,
            company: $this->currentCompany,
            user: $this->currentUser,
            customCost: $estimatedCost,
        );
    }

    /**
     * Get available locations (countries)
     */
    public static function getLocations(): array
    {
        return [
            '2250' => ['name' => 'France', 'code' => 'FR'],
            '2826' => ['name' => 'United Kingdom', 'code' => 'GB'],
            '2840' => ['name' => 'United States', 'code' => 'US'],
            '2276' => ['name' => 'Germany', 'code' => 'DE'],
            '2724' => ['name' => 'Spain', 'code' => 'ES'],
            '2380' => ['name' => 'Italy', 'code' => 'IT'],
            '2056' => ['name' => 'Belgium', 'code' => 'BE'],
            '2756' => ['name' => 'Switzerland', 'code' => 'CH'],
            '2124' => ['name' => 'Canada', 'code' => 'CA'],
        ];
    }

    /**
     * Get location code from country code
     */
    public static function getLocationCodeFromCountry(string $countryCode): string
    {
        $mapping = [
            'FR' => '2250',
            'GB' => '2826',
            'UK' => '2826',
            'US' => '2840',
            'DE' => '2276',
            'ES' => '2724',
            'IT' => '2380',
            'BE' => '2056',
            'CH' => '2756',
            'CA' => '2124',
        ];

        return $mapping[strtoupper($countryCode)] ?? '2250';
    }
}
