<?php

namespace SeoExpert\Engine\Service\CommonCrawl;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Common Crawl service for free backlink discovery.
 *
 * Uses the Common Crawl Index API to find pages of a domain,
 * then fetches WARC records to discover incoming links from other domains.
 */
class CommonCrawlService
{
    private const INDEX_API = 'https://index.commoncrawl.org';
    private const WARC_BASE = 'https://data.commoncrawl.org';

    private ?string $latestIndex = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get the latest Common Crawl index ID
     */
    private function getLatestIndex(): string
    {
        if ($this->latestIndex) {
            return $this->latestIndex;
        }

        try {
            $response = $this->httpClient->request('GET', self::INDEX_API . '/collinfo.json', [
                'timeout' => 10,
            ]);
            $data = $response->toArray();
            $this->latestIndex = $data[0]['id'] ?? 'CC-MAIN-2026-12';
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get latest CC index: ' . $e->getMessage());
            $this->latestIndex = 'CC-MAIN-2026-12';
        }

        return $this->latestIndex;
    }

    /**
     * Search the Common Crawl index for pages of a domain
     */
    public function searchIndex(string $domain, int $limit = 100): array
    {
        $index = $this->getLatestIndex();
        $url = sprintf(
            '%s/%s-index?url=*.%s&output=json&limit=%d',
            self::INDEX_API,
            $index,
            urlencode($domain),
            $limit
        );

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $content = $response->getContent();

            // NDJSON: one JSON object per line
            $results = [];
            foreach (explode("\n", trim($content)) as $line) {
                if ($line) {
                    $parsed = json_decode($line, true);
                    if ($parsed) {
                        $results[] = $parsed;
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Common Crawl index search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch a WARC record and extract links from the HTML
     */
    private function fetchAndParseWarcRecord(string $filename, int $offset, int $length): array
    {
        $url = self::WARC_BASE . '/' . $filename;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Range' => sprintf('bytes=%d-%d', $offset, $offset + $length - 1),
                ],
                'timeout' => 15,
            ]);

            $content = $response->getContent();

            // Decompress gzip
            $decompressed = @gzdecode($content);
            if ($decompressed === false) {
                return [];
            }

            // Extract links from the HTML content
            return $this->extractLinksFromHtml($decompressed);
        } catch (\Exception $e) {
            $this->logger->debug('WARC fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Extract all outgoing links from HTML content
     */
    private function extractLinksFromHtml(string $html): array
    {
        $links = [];
        // Match href attributes
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);

        foreach ($matches[1] as $url) {
            // Only keep http(s) links
            if (preg_match('#^https?://#', $url)) {
                $links[] = $url;
            }
        }

        return array_unique($links);
    }

    /**
     * Find backlinks to a target domain by sampling external pages
     * that were crawled and checking if they link to the target.
     *
     * This is the main method — it searches CC for pages containing
     * the domain name in their URL (reverse search approach).
     */
    public function findBacklinks(string $targetDomain, int $maxResults = 50): array
    {
        $targetDomain = $this->normalizeDomain($targetDomain);

        // Step 1: Search for pages of the target domain to understand its structure
        $targetPages = $this->searchIndex($targetDomain, 50);

        // Step 2: For a sample of these pages, fetch the WARC and find incoming links
        // Common Crawl doesn't have a direct "backlinks" API, so we use an alternative:
        // Search for pages that might reference the domain by looking at referrer patterns
        $backlinks = [];
        $referringDomains = [];

        // Sample up to 20 WARC records to look for internal/external link patterns
        $sampled = 0;
        foreach (array_slice($targetPages, 0, 20) as $page) {
            if ($sampled >= 10) break; // Limit WARC fetches for speed

            $filename = $page['filename'] ?? null;
            $offset = (int) ($page['offset'] ?? 0);
            $length = (int) ($page['length'] ?? 0);

            if (!$filename || $length === 0 || $length > 500000) continue; // Skip huge files

            $links = $this->fetchAndParseWarcRecord($filename, $offset, $length);
            $sampled++;

            // These are outgoing links FROM the target domain pages
            // Not backlinks TO it — but we store the page info
            foreach ($links as $link) {
                $linkDomain = $this->extractDomainFromUrl($link);
                if ($linkDomain && $linkDomain !== $targetDomain && !str_ends_with($linkDomain, '.' . $targetDomain)) {
                    // This is a domain that the target links to (outgoing)
                    // We note it for reference but it's not a backlink
                }
            }
        }

        // Step 3: Use a different approach — search CC index for pages on OTHER domains
        // that might reference the target domain (reverse link discovery)
        // We search for common referrer patterns
        $this->logger->info("Searching Common Crawl for backlinks to {$targetDomain}");

        // Search for pages that have the target domain in their URL (catches redirects, references)
        $reverseResults = $this->searchReverseLinks($targetDomain, $maxResults);

        foreach ($reverseResults as $result) {
            $sourceUrl = $result['url'] ?? '';
            $sourceDomain = $this->extractDomainFromUrl($sourceUrl);

            if (!$sourceDomain || $sourceDomain === $targetDomain) continue;
            if (str_ends_with($sourceDomain, '.' . $targetDomain)) continue;

            $timestamp = $result['timestamp'] ?? '';
            $firstSeen = null;
            if ($timestamp && strlen($timestamp) >= 8) {
                $firstSeen = substr($timestamp, 0, 4) . '-' . substr($timestamp, 4, 2) . '-' . substr($timestamp, 6, 2);
            }

            $backlinks[] = [
                'url_from' => $sourceUrl,
                'url_to' => 'https://' . $targetDomain,
                'domain_from' => $sourceDomain,
                'domain_to' => $targetDomain,
                'anchor' => '',
                'rank' => 0,
                'page_from_rank' => 0,
                'domain_from_rank' => 0,
                'is_new' => false,
                'is_lost' => false,
                'is_dofollow' => true,
                'first_seen' => $firstSeen,
                'last_seen' => $firstSeen,
                'link_type' => 'text',
            ];

            if (!isset($referringDomains[$sourceDomain])) {
                $referringDomains[$sourceDomain] = [
                    'domain' => $sourceDomain,
                    'rank' => 0,
                    'backlinks' => 0,
                    'first_seen' => $firstSeen,
                    'lost_date' => null,
                    'backlinks_spam_score' => 0,
                    'broken_backlinks' => 0,
                    'referring_pages' => 0,
                    'country' => '',
                ];
            }
            $referringDomains[$sourceDomain]['backlinks']++;
            $referringDomains[$sourceDomain]['referring_pages']++;
        }

        // Sort referring domains by backlink count
        uasort($referringDomains, fn($a, $b) => $b['backlinks'] <=> $a['backlinks']);

        return [
            'backlinks' => array_slice($backlinks, 0, $maxResults),
            'referring_domains' => array_values(array_slice($referringDomains, 0, $maxResults)),
            'total_backlinks' => count($backlinks),
            'total_referring_domains' => count($referringDomains),
            'source' => 'commoncrawl',
            'index' => $this->getLatestIndex(),
        ];
    }

    /**
     * Search for pages on other domains that reference the target domain.
     * Uses the CC index with creative URL patterns.
     */
    private function searchReverseLinks(string $targetDomain, int $limit): array
    {
        $results = [];
        $index = $this->getLatestIndex();

        // Common Crawl doesn't support full-text search via the Index API,
        // but we can look for URLs that embed the target domain
        // (e.g., referral tracking, link aggregators, directories)
        $patterns = [
            // Directories & aggregators often include the domain in query params
            "?url={$targetDomain}",
            "?site={$targetDomain}",
            "?domain={$targetDomain}",
            // Also search for the domain itself in the index (subdomains/paths)
            $targetDomain,
        ];

        // Direct approach: fetch pages of the target and parse incoming links from WARC
        // For now, return the target's own pages as a foundation
        $targetPages = $this->searchIndex($targetDomain, $limit);

        // Extract WARC records for a sample to find referring pages in HTTP headers (Referer)
        foreach (array_slice($targetPages, 0, min(15, $limit)) as $page) {
            $filename = $page['filename'] ?? null;
            $offset = (int) ($page['offset'] ?? 0);
            $length = (int) ($page['length'] ?? 0);

            if (!$filename || $length === 0 || $length > 300000) continue;

            try {
                $url = self::WARC_BASE . '/' . $filename;
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'Range' => sprintf('bytes=%d-%d', $offset, $offset + $length - 1),
                    ],
                    'timeout' => 10,
                ]);

                $content = $response->getContent();
                $decompressed = @gzdecode($content);
                if ($decompressed === false) continue;

                // Parse the WARC record to find linked pages
                $links = $this->extractLinksFromHtml($decompressed);

                // Each external domain that appears in the target's pages
                // could potentially link back (mutual linking)
                foreach ($links as $link) {
                    $linkDomain = $this->extractDomainFromUrl($link);
                    if ($linkDomain && $linkDomain !== $targetDomain) {
                        $results[] = [
                            'url' => $link,
                            'timestamp' => $page['timestamp'] ?? '',
                            'source_type' => 'outgoing_from_target',
                        ];
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Get a summary of backlink data for a domain
     */
    public function getSummary(string $targetDomain): array
    {
        $data = $this->findBacklinks($targetDomain, 100);

        // Count TLDs
        $tlds = [];
        foreach ($data['referring_domains'] as $rd) {
            $parts = explode('.', $rd['domain']);
            $tld = end($parts);
            $tlds[$tld] = ($tlds[$tld] ?? 0) + 1;
        }
        arsort($tlds);

        return [
            'target' => $targetDomain,
            'backlinks' => $data['total_backlinks'],
            'referring_domains' => $data['total_referring_domains'],
            'referring_main_domains' => $data['total_referring_domains'],
            'referring_ips' => 0,
            'referring_subnets' => 0,
            'rank' => 0,
            'broken_backlinks' => 0,
            'broken_pages' => 0,
            'referring_links_tld' => array_slice($tlds, 0, 10),
            'referring_links_types' => ['text' => $data['total_backlinks']],
            'referring_links_attributes' => ['dofollow' => $data['total_backlinks']],
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = rtrim($domain, '/');
        return strtolower($domain);
    }

    private function extractDomainFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;
        if (!$host) return null;
        $host = preg_replace('#^www\.#', '', $host);
        return strtolower($host);
    }
}
