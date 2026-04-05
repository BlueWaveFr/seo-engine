<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use SeoExpert\Engine\Entity\MozData;
use SeoExpert\Engine\Entity\MozDataSnapshot;
use App\Repository\MozDataRepository;
use App\Repository\MozDataSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MozService
{
    private const API_URL = 'https://lsapi.seomoz.com/v2';
    private const CACHE_MAX_AGE_HOURS = 24;
    private const TREND_COMPARISON_DAYS = 7; // Compare with 7 days ago

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager,
        private EntityManagerInterface $entityManager,
        private MozDataRepository $mozDataRepository,
        private MozDataSnapshotRepository $snapshotRepository
    ) {}

    /**
     * Get domain overview with caching (refreshes once per day)
     */
    public function getDomainOverview(string $domain, bool $forceRefresh = false): ?array
    {
        // Normalize domain
        $normalizedDomain = $this->normalizeDomain($domain);

        // Check cache first (unless force refresh)
        if (!$forceRefresh) {
            $cached = $this->mozDataRepository->findFreshByDomain($normalizedDomain, self::CACHE_MAX_AGE_HOURS);
            if ($cached) {
                return $this->formatCachedData($cached);
            }
        }

        // Fetch fresh data from API
        $metrics = $this->fetchUrlMetrics($domain);
        $links = $this->fetchLinkingDomains($domain, 20);

        if (!$metrics && !$links) {
            return null;
        }

        // Store in cache
        $mozData = $this->mozDataRepository->findByDomain($normalizedDomain) ?? new MozData();
        $mozData->setDomain($normalizedDomain);
        $mozData->setDomainAuthority($metrics['domainAuthority'] ?? null);
        $mozData->setPageAuthority($metrics['pageAuthority'] ?? null);
        $mozData->setSpamScore($metrics['spamScore'] ?? null);
        $mozData->setLinkingRootDomains($metrics['linkingRootDomains'] ?? null);
        $mozData->setExternalLinks($metrics['externalLinks'] ?? null);
        $mozData->setTopBacklinks($links['links'] ?? []);
        $mozData->setBacklinkCount($links['linkCount'] ?? 0);
        $mozData->setRawMetrics($metrics['rawData'] ?? null);
        $mozData->setFetchedAt(new \DateTime());
        $mozData->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($mozData);

        // Save a snapshot for historical tracking (once per day)
        $this->saveSnapshot($normalizedDomain, $mozData);

        $this->entityManager->flush();

        return $this->formatCachedData($mozData);
    }

    /**
     * Save a daily snapshot for trend tracking
     */
    private function saveSnapshot(string $domain, MozData $mozData): void
    {
        // Only save one snapshot per day
        if ($this->snapshotRepository->hasSnapshotForToday($domain)) {
            return;
        }

        $snapshot = new MozDataSnapshot();
        $snapshot->setDomain($domain);
        $snapshot->setDomainAuthority($mozData->getDomainAuthority());
        $snapshot->setPageAuthority($mozData->getPageAuthority());
        $snapshot->setSpamScore($mozData->getSpamScore());
        $snapshot->setLinkingRootDomains($mozData->getLinkingRootDomains());
        $snapshot->setExternalLinks($mozData->getExternalLinks());

        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $snapshot->setSnapshotDate($today);

        $this->entityManager->persist($snapshot);
    }

    /**
     * Calculate trends by comparing with snapshot from X days ago
     */
    private function calculateTrends(string $domain, MozData $current): array
    {
        $previousSnapshot = $this->snapshotRepository->findSnapshotFromDaysAgo(
            $domain,
            self::TREND_COMPARISON_DAYS
        );

        if (!$previousSnapshot) {
            return [];
        }

        $trends = [];

        // Domain Authority trend
        if ($current->getDomainAuthority() !== null && $previousSnapshot->getDomainAuthority() !== null) {
            $trends['domainAuthority'] = $this->calculateChange(
                $current->getDomainAuthority(),
                $previousSnapshot->getDomainAuthority()
            );
        }

        // Page Authority trend
        if ($current->getPageAuthority() !== null && $previousSnapshot->getPageAuthority() !== null) {
            $trends['pageAuthority'] = $this->calculateChange(
                $current->getPageAuthority(),
                $previousSnapshot->getPageAuthority()
            );
        }

        // Linking Root Domains trend
        if ($current->getLinkingRootDomains() !== null && $previousSnapshot->getLinkingRootDomains() !== null) {
            $trends['linkingRootDomains'] = $this->calculateChange(
                $current->getLinkingRootDomains(),
                $previousSnapshot->getLinkingRootDomains()
            );
        }

        // External Links trend
        if ($current->getExternalLinks() !== null && $previousSnapshot->getExternalLinks() !== null) {
            $trends['externalLinks'] = $this->calculateChange(
                $current->getExternalLinks(),
                $previousSnapshot->getExternalLinks()
            );
        }

        // Spam Score trend (lower is better, so we invert the logic)
        if ($current->getSpamScore() !== null && $previousSnapshot->getSpamScore() !== null) {
            $trends['spamScore'] = $this->calculateChange(
                $current->getSpamScore(),
                $previousSnapshot->getSpamScore(),
                true // lower is better
            );
        }

        return $trends;
    }

    /**
     * Calculate percentage change between current and previous values
     */
    private function calculateChange(int $current, int $previous, bool $lowerIsBetter = false): array
    {
        $difference = $current - $previous;
        $percentChange = $previous > 0 ? (($current - $previous) / $previous) * 100 : 0;

        // Determine if the change is positive (good)
        $isPositive = $lowerIsBetter ? $difference < 0 : $difference > 0;

        return [
            'current' => $current,
            'previous' => $previous,
            'difference' => $difference,
            'percentChange' => round($percentChange, 1),
            'isPositive' => $isPositive,
        ];
    }

    /**
     * Get URL metrics from MOZ API (internal - no caching)
     */
    public function getUrlMetrics(string $url): ?array
    {
        return $this->fetchUrlMetrics($url);
    }

    /**
     * Get linking domains from MOZ API (internal - no caching)
     */
    public function getLinkingDomains(string $url, int $limit = 50): ?array
    {
        return $this->fetchLinkingDomains($url, $limit);
    }

    private function fetchUrlMetrics(string $url): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_MOZ);

        if (!$credentials || !$credentials['apiKey'] || !$credentials['apiSecret']) {
            return null;
        }

        $response = $this->httpClient->request('POST', self::API_URL . '/url_metrics', [
            'auth_basic' => [$credentials['apiKey'], $credentials['apiSecret']],
            'json' => [
                'targets' => [$url],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        if (empty($data['results'])) {
            return null;
        }

        $result = $data['results'][0];

        return [
            'page' => $result['page'] ?? $url,
            'domainAuthority' => $result['domain_authority'] ?? null,
            'pageAuthority' => $result['page_authority'] ?? null,
            'linkingRootDomains' => $result['root_domains_to_page'] ?? null,
            'externalLinks' => $result['external_pages_to_page'] ?? null,
            'spamScore' => $result['spam_score'] ?? null,
            'rawData' => $result,
        ];
    }

    private function fetchLinkingDomains(string $url, int $limit = 50): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_MOZ);

        if (!$credentials || !$credentials['apiKey'] || !$credentials['apiSecret']) {
            return null;
        }

        $response = $this->httpClient->request('POST', self::API_URL . '/links', [
            'auth_basic' => [$credentials['apiKey'], $credentials['apiSecret']],
            'json' => [
                'target' => $url,
                'target_scope' => 'root_domain',
                'limit' => $limit,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        // Map the results - MOZ API v2 uses nested source/target objects
        $links = array_map(function ($link) {
            $source = $link['source'] ?? [];
            $target = $link['target'] ?? [];

            return [
                'source_url' => $source['page'] ?? null,
                'source_domain' => $source['root_domain'] ?? null,
                'source_title' => $source['title'] ?? null,
                'target_url' => $target['page'] ?? null,
                'anchor_text' => $link['anchor_text'] ?? null,
                'domain_authority' => $source['domain_authority'] ?? null,
                'page_authority' => $source['page_authority'] ?? null,
                'spam_score' => $source['spam_score'] ?? null,
                'nofollow' => $link['nofollow'] ?? false,
                'date_first_seen' => $link['date_first_seen'] ?? null,
                'date_last_seen' => $link['date_last_seen'] ?? null,
            ];
        }, $data['results'] ?? []);

        return [
            'target' => $url,
            'linkCount' => count($links),
            'links' => $links,
        ];
    }

    private function formatCachedData(MozData $mozData): array
    {
        // Calculate trends compared to 7 days ago
        $trends = $this->calculateTrends($mozData->getDomain(), $mozData);

        return [
            'domain' => $mozData->getDomain(),
            'metrics' => [
                'domainAuthority' => $mozData->getDomainAuthority(),
                'pageAuthority' => $mozData->getPageAuthority(),
                'spamScore' => $mozData->getSpamScore(),
                'linkingRootDomains' => $mozData->getLinkingRootDomains(),
                'externalLinks' => $mozData->getExternalLinks(),
            ],
            'trends' => $trends,
            'trendPeriodDays' => self::TREND_COMPARISON_DAYS,
            'topBacklinks' => $mozData->getTopBacklinks() ?? [],
            'backlinkCount' => $mozData->getBacklinkCount() ?? 0,
            'fetchedAt' => $mozData->getFetchedAt()?->format('c'),
            'isStale' => $mozData->isStale(self::CACHE_MAX_AGE_HOURS),
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        // Remove protocol
        $domain = preg_replace('#^https?://#', '', $domain);
        // Remove trailing slash
        $domain = rtrim($domain, '/');
        // Remove www.
        $domain = preg_replace('#^www\.#', '', $domain);
        // Get root domain only
        $parts = explode('/', $domain);
        return strtolower($parts[0]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyManager->isConfigured(ApiKey::PROVIDER_MOZ);
    }
}
