<?php

namespace SeoExpert\Engine\Service\Audit;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class SiteCrawlerService
{
    private const USER_AGENT = 'SEOAuditBot/1.0 (+https://seo-audit.app/bot)';
    private const MAX_PAGES = 100;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Perform a comprehensive technical audit of a website
     */
    public function auditSite(string $url, int $maxPages = 50): array
    {
        $startTime = microtime(true);
        $baseUrl = $this->getBaseUrl($url);
        $domain = parse_url($baseUrl, PHP_URL_HOST);

        $audit = [
            'url' => $url,
            'base_url' => $baseUrl,
            'domain' => $domain,
            'crawled_at' => (new \DateTimeImmutable())->format('c'),
            'technical' => [],
            'seo' => [],
            'security' => [],
            'structure' => [],
            'issues' => [],
            'pages_crawled' => 0,
        ];

        try {
            // 1. Check robots.txt
            $audit['technical']['robots_txt'] = $this->analyzeRobotsTxt($baseUrl);

            // 2. Check sitemap.xml
            $audit['technical']['sitemap'] = $this->analyzeSitemap($baseUrl);

            // 3. Analyze homepage
            $homepageAudit = $this->analyzeUrl($url);
            $audit['seo']['homepage'] = $homepageAudit;

            // 4. Check SSL/HTTPS
            $audit['security']['https'] = $this->checkHttps($url);

            // 5. Check redirects (www vs non-www, http vs https)
            $audit['technical']['redirects'] = $this->checkRedirects($domain);

            // 6. Crawl internal pages (limited)
            $crawlResults = $this->crawlInternalPages($baseUrl, min($maxPages, self::MAX_PAGES));
            $audit['structure'] = $crawlResults['structure'];
            $audit['pages_crawled'] = $crawlResults['pages_crawled'];
            $audit['issues'] = array_merge($audit['issues'], $crawlResults['issues']);

            // 7. Aggregate issues from all checks
            $audit['issues'] = array_merge(
                $audit['issues'],
                $this->aggregateIssues($audit)
            );

            // Calculate scores
            $audit['scores'] = $this->calculateScores($audit);

        } catch (\Exception $e) {
            $this->logger->error('Site audit failed: ' . $e->getMessage(), [
                'url' => $url,
            ]);
            $audit['error'] = $e->getMessage();
        }

        $audit['duration_ms'] = round((microtime(true) - $startTime) * 1000);

        return $audit;
    }

    /**
     * Analyze robots.txt
     */
    public function analyzeRobotsTxt(string $baseUrl): array
    {
        $robotsUrl = rtrim($baseUrl, '/') . '/robots.txt';

        try {
            $response = $this->httpClient->request('GET', $robotsUrl, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => ['User-Agent' => self::USER_AGENT],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                return [
                    'exists' => false,
                    'status_code' => $statusCode,
                    'issues' => ['robots.txt not found or inaccessible'],
                ];
            }

            $content = $response->getContent();
            $lines = array_filter(array_map('trim', explode("\n", $content)));

            $rules = [];
            $sitemaps = [];
            $currentUserAgent = '*';

            foreach ($lines as $line) {
                // Skip comments
                if (str_starts_with($line, '#')) {
                    continue;
                }

                if (preg_match('/^User-agent:\s*(.+)$/i', $line, $matches)) {
                    $currentUserAgent = trim($matches[1]);
                } elseif (preg_match('/^Disallow:\s*(.*)$/i', $line, $matches)) {
                    $rules[$currentUserAgent]['disallow'][] = trim($matches[1]);
                } elseif (preg_match('/^Allow:\s*(.+)$/i', $line, $matches)) {
                    $rules[$currentUserAgent]['allow'][] = trim($matches[1]);
                } elseif (preg_match('/^Sitemap:\s*(.+)$/i', $line, $matches)) {
                    $sitemaps[] = trim($matches[1]);
                }
            }

            // Check for potential issues
            $issues = [];
            if (isset($rules['*']['disallow']) && in_array('/', $rules['*']['disallow'])) {
                $issues[] = 'CRITICAL: robots.txt blocks all crawlers (Disallow: /)';
            }
            if (isset($rules['Googlebot']['disallow']) && in_array('/', $rules['Googlebot']['disallow'])) {
                $issues[] = 'CRITICAL: robots.txt blocks Googlebot';
            }
            if (empty($sitemaps)) {
                $issues[] = 'No sitemap reference found in robots.txt';
            }

            return [
                'exists' => true,
                'status_code' => 200,
                'rules' => $rules,
                'sitemaps' => $sitemaps,
                'content_length' => strlen($content),
                'issues' => $issues,
            ];
        } catch (\Exception $e) {
            return [
                'exists' => false,
                'error' => $e->getMessage(),
                'issues' => ['Failed to fetch robots.txt: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Analyze sitemap.xml
     */
    public function analyzeSitemap(string $baseUrl): array
    {
        $sitemapUrls = [
            rtrim($baseUrl, '/') . '/sitemap.xml',
            rtrim($baseUrl, '/') . '/sitemap_index.xml',
            rtrim($baseUrl, '/') . '/sitemap/',
        ];

        foreach ($sitemapUrls as $sitemapUrl) {
            try {
                $response = $this->httpClient->request('GET', $sitemapUrl, [
                    'timeout' => self::REQUEST_TIMEOUT,
                    'headers' => ['User-Agent' => self::USER_AGENT],
                ]);

                if ($response->getStatusCode() === 200) {
                    $content = $response->getContent();

                    // Check if it's valid XML
                    libxml_use_internal_errors(true);
                    $xml = simplexml_load_string($content);

                    if ($xml === false) {
                        continue;
                    }

                    $urlCount = 0;
                    $sitemapIndex = [];
                    $issues = [];

                    // Check if it's a sitemap index
                    if ($xml->getName() === 'sitemapindex') {
                        foreach ($xml->sitemap as $sitemap) {
                            $sitemapIndex[] = [
                                'loc' => (string) $sitemap->loc,
                                'lastmod' => isset($sitemap->lastmod) ? (string) $sitemap->lastmod : null,
                            ];
                        }
                    } else {
                        // Regular sitemap
                        $urlCount = count($xml->url);

                        // Check for issues
                        $hasLastmod = false;
                        $hasPriority = false;

                        foreach ($xml->url as $url) {
                            if (isset($url->lastmod)) $hasLastmod = true;
                            if (isset($url->priority)) $hasPriority = true;
                        }

                        if (!$hasLastmod) {
                            $issues[] = 'Sitemap URLs are missing lastmod dates';
                        }
                    }

                    return [
                        'exists' => true,
                        'url' => $sitemapUrl,
                        'type' => !empty($sitemapIndex) ? 'index' : 'urlset',
                        'url_count' => $urlCount,
                        'sitemap_index' => $sitemapIndex,
                        'issues' => $issues,
                    ];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return [
            'exists' => false,
            'issues' => ['No sitemap.xml found'],
        ];
    }

    /**
     * Analyze a single URL for SEO factors
     */
    public function analyzeUrl(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders();
            $content = $response->getContent();

            $crawler = new Crawler($content);
            $issues = [];

            // Title
            $title = $this->extractText($crawler, 'title');
            $titleLength = mb_strlen($title);
            if (empty($title)) {
                $issues[] = ['type' => 'critical', 'message' => 'Missing title tag'];
            } elseif ($titleLength < 30) {
                $issues[] = ['type' => 'warning', 'message' => "Title too short ({$titleLength} chars, recommended: 50-60)"];
            } elseif ($titleLength > 60) {
                $issues[] = ['type' => 'warning', 'message' => "Title too long ({$titleLength} chars, recommended: 50-60)"];
            }

            // Meta description
            $metaDescription = $this->extractMeta($crawler, 'description');
            $metaLength = mb_strlen($metaDescription);
            if (empty($metaDescription)) {
                $issues[] = ['type' => 'warning', 'message' => 'Missing meta description'];
            } elseif ($metaLength < 120) {
                $issues[] = ['type' => 'info', 'message' => "Meta description short ({$metaLength} chars, recommended: 150-160)"];
            } elseif ($metaLength > 160) {
                $issues[] = ['type' => 'info', 'message' => "Meta description too long ({$metaLength} chars, recommended: 150-160)"];
            }

            // H1
            $h1Tags = $crawler->filter('h1');
            $h1Count = $h1Tags->count();
            $h1Text = $h1Count > 0 ? $h1Tags->first()->text() : null;
            if ($h1Count === 0) {
                $issues[] = ['type' => 'critical', 'message' => 'Missing H1 tag'];
            } elseif ($h1Count > 1) {
                $issues[] = ['type' => 'warning', 'message' => "Multiple H1 tags ({$h1Count} found)"];
            }

            // Heading structure
            $headings = $this->analyzeHeadingStructure($crawler);

            // Images
            $images = $this->analyzeImages($crawler);
            if ($images['without_alt'] > 0) {
                $issues[] = ['type' => 'warning', 'message' => "{$images['without_alt']} images without alt attribute"];
            }

            // Links
            $links = $this->analyzeLinks($crawler, $url);

            // Canonical
            $canonical = $this->extractCanonical($crawler);
            if (empty($canonical)) {
                $issues[] = ['type' => 'warning', 'message' => 'Missing canonical tag'];
            }

            // Meta robots
            $metaRobots = $this->extractMeta($crawler, 'robots');
            if (str_contains(strtolower($metaRobots), 'noindex')) {
                $issues[] = ['type' => 'critical', 'message' => 'Page has noindex directive'];
            }

            // Open Graph
            $ogTags = $this->extractOpenGraph($crawler);

            // Schema.org structured data
            $structuredData = $this->extractStructuredData($crawler);

            // Language
            $htmlLang = $crawler->filter('html')->attr('lang');

            // Viewport
            $viewport = $this->extractMeta($crawler, 'viewport');
            if (empty($viewport)) {
                $issues[] = ['type' => 'warning', 'message' => 'Missing viewport meta tag (mobile optimization)'];
            }

            return [
                'url' => $url,
                'status_code' => $statusCode,
                'title' => $title,
                'title_length' => $titleLength,
                'meta_description' => $metaDescription,
                'meta_description_length' => $metaLength,
                'h1' => $h1Text,
                'h1_count' => $h1Count,
                'headings' => $headings,
                'images' => $images,
                'links' => $links,
                'canonical' => $canonical,
                'meta_robots' => $metaRobots,
                'og_tags' => $ogTags,
                'structured_data' => $structuredData,
                'html_lang' => $htmlLang,
                'viewport' => $viewport,
                'content_length' => strlen($content),
                'word_count' => str_word_count(strip_tags($content)),
                'issues' => $issues,
            ];
        } catch (\Exception $e) {
            return [
                'url' => $url,
                'error' => $e->getMessage(),
                'issues' => [['type' => 'critical', 'message' => 'Failed to fetch URL: ' . $e->getMessage()]],
            ];
        }
    }

    /**
     * Check HTTPS configuration
     */
    public function checkHttps(string $url): array
    {
        $parsedUrl = parse_url($url);
        $isHttps = ($parsedUrl['scheme'] ?? '') === 'https';
        $issues = [];

        if (!$isHttps) {
            $issues[] = 'Site is not using HTTPS';
        }

        // Check SSL certificate
        $host = $parsedUrl['host'] ?? '';
        $sslInfo = null;

        if ($isHttps && $host) {
            try {
                $context = stream_context_create([
                    'ssl' => [
                        'capture_peer_cert' => true,
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]);

                $socket = @stream_socket_client(
                    "ssl://{$host}:443",
                    $errno,
                    $errstr,
                    30,
                    STREAM_CLIENT_CONNECT,
                    $context
                );

                if ($socket) {
                    $params = stream_context_get_params($socket);
                    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

                    if ($cert) {
                        $validFrom = new \DateTimeImmutable('@' . $cert['validFrom_time_t']);
                        $validTo = new \DateTimeImmutable('@' . $cert['validTo_time_t']);
                        $now = new \DateTimeImmutable();

                        $sslInfo = [
                            'valid' => $now >= $validFrom && $now <= $validTo,
                            'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown',
                            'valid_from' => $validFrom->format('Y-m-d'),
                            'valid_to' => $validTo->format('Y-m-d'),
                            'days_remaining' => $now->diff($validTo)->days,
                        ];

                        if ($sslInfo['days_remaining'] < 30) {
                            $issues[] = "SSL certificate expires in {$sslInfo['days_remaining']} days";
                        }
                    }
                    fclose($socket);
                }
            } catch (\Exception $e) {
                $issues[] = 'Failed to verify SSL certificate: ' . $e->getMessage();
            }
        }

        return [
            'https' => $isHttps,
            'ssl' => $sslInfo,
            'issues' => $issues,
        ];
    }

    /**
     * Check redirects configuration
     */
    public function checkRedirects(string $domain): array
    {
        $variants = [
            "http://{$domain}",
            "https://{$domain}",
            "http://www.{$domain}",
            "https://www.{$domain}",
        ];

        $results = [];
        $issues = [];

        foreach ($variants as $variant) {
            try {
                $response = $this->httpClient->request('GET', $variant, [
                    'timeout' => 15,
                    'max_redirects' => 0,
                    'headers' => ['User-Agent' => self::USER_AGENT],
                ]);

                $statusCode = $response->getStatusCode();
                $location = $response->getHeaders(false)['location'][0] ?? null;

                $results[$variant] = [
                    'status_code' => $statusCode,
                    'redirects_to' => $location,
                ];
            } catch (\Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface $e) {
                $response = $e->getResponse();
                $results[$variant] = [
                    'status_code' => $response->getStatusCode(),
                    'redirects_to' => $response->getHeaders(false)['location'][0] ?? null,
                ];
            } catch (\Exception $e) {
                $results[$variant] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Check for redirect chain issues
        $finalDestinations = [];
        foreach ($results as $url => $result) {
            if (isset($result['redirects_to'])) {
                $finalDestinations[$url] = $result['redirects_to'];
            } elseif (isset($result['status_code']) && $result['status_code'] === 200) {
                $finalDestinations[$url] = $url;
            }
        }

        // All should redirect to the same canonical URL
        $uniqueDestinations = array_unique(array_values($finalDestinations));
        if (count($uniqueDestinations) > 1) {
            $issues[] = 'Inconsistent redirects: different domain variants lead to different destinations';
        }

        // HTTP should redirect to HTTPS
        if (isset($results["http://{$domain}"]['status_code']) &&
            $results["http://{$domain}"]['status_code'] === 200) {
            $issues[] = 'HTTP version does not redirect to HTTPS';
        }

        return [
            'variants' => $results,
            'canonical_url' => $uniqueDestinations[0] ?? null,
            'issues' => $issues,
        ];
    }

    /**
     * Crawl internal pages to analyze site structure
     */
    private function crawlInternalPages(string $baseUrl, int $maxPages): array
    {
        $visited = [];
        $toVisit = [$baseUrl];
        $structure = [
            'pages' => [],
            'broken_links' => [],
            'redirect_chains' => [],
        ];
        $issues = [];
        $domain = parse_url($baseUrl, PHP_URL_HOST);

        while (!empty($toVisit) && count($visited) < $maxPages) {
            $url = array_shift($toVisit);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 15,
                    'headers' => ['User-Agent' => self::USER_AGENT],
                ]);

                $statusCode = $response->getStatusCode();
                $content = $response->getContent();

                $structure['pages'][] = [
                    'url' => $url,
                    'status_code' => $statusCode,
                    'depth' => $this->calculateDepth($url, $baseUrl),
                ];

                if ($statusCode >= 400) {
                    $structure['broken_links'][] = [
                        'url' => $url,
                        'status_code' => $statusCode,
                    ];
                    continue;
                }

                // Extract internal links for further crawling
                $crawler = new Crawler($content);
                $links = $crawler->filter('a[href]')->each(function (Crawler $node) {
                    return $node->attr('href');
                });

                foreach ($links as $link) {
                    $absoluteUrl = $this->makeAbsoluteUrl($link, $url);
                    if ($absoluteUrl &&
                        $this->isSameDomain($absoluteUrl, $domain) &&
                        !isset($visited[$absoluteUrl]) &&
                        !in_array($absoluteUrl, $toVisit)) {
                        $toVisit[] = $absoluteUrl;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug('Failed to crawl: ' . $url, ['error' => $e->getMessage()]);
            }
        }

        // Analyze structure
        $depths = array_column($structure['pages'], 'depth');
        $maxDepth = !empty($depths) ? max($depths) : 0;

        if ($maxDepth > 4) {
            $issues[] = ['type' => 'warning', 'message' => "Deep page structure detected (max depth: {$maxDepth}). Consider flattening."];
        }

        if (count($structure['broken_links']) > 0) {
            $brokenCount = count($structure['broken_links']);
            $issues[] = ['type' => 'critical', 'message' => "{$brokenCount} broken internal links found"];
        }

        return [
            'structure' => $structure,
            'pages_crawled' => count($visited),
            'issues' => $issues,
        ];
    }

    // Helper methods

    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    }

    private function extractText(Crawler $crawler, string $selector): string
    {
        try {
            $element = $crawler->filter($selector);
            return $element->count() > 0 ? trim($element->first()->text()) : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractMeta(Crawler $crawler, string $name): string
    {
        try {
            $element = $crawler->filter("meta[name='{$name}']");
            if ($element->count() === 0) {
                $element = $crawler->filter("meta[property='{$name}']");
            }
            return $element->count() > 0 ? ($element->first()->attr('content') ?? '') : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractCanonical(Crawler $crawler): ?string
    {
        try {
            $element = $crawler->filter('link[rel="canonical"]');
            return $element->count() > 0 ? $element->first()->attr('href') : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function analyzeHeadingStructure(Crawler $crawler): array
    {
        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $tags = $crawler->filter("h{$i}");
            $headings["h{$i}"] = $tags->count();
        }
        return $headings;
    }

    private function analyzeImages(Crawler $crawler): array
    {
        $images = $crawler->filter('img');
        $total = $images->count();
        $withoutAlt = 0;

        $images->each(function (Crawler $img) use (&$withoutAlt) {
            $alt = $img->attr('alt');
            if ($alt === null || $alt === '') {
                $withoutAlt++;
            }
        });

        return [
            'total' => $total,
            'without_alt' => $withoutAlt,
            'with_alt' => $total - $withoutAlt,
        ];
    }

    private function analyzeLinks(Crawler $crawler, string $pageUrl): array
    {
        $domain = parse_url($pageUrl, PHP_URL_HOST);
        $internal = 0;
        $external = 0;
        $nofollow = 0;

        $crawler->filter('a[href]')->each(function (Crawler $link) use ($domain, &$internal, &$external, &$nofollow) {
            $href = $link->attr('href');
            $rel = $link->attr('rel') ?? '';

            if (str_contains($rel, 'nofollow')) {
                $nofollow++;
            }

            $linkDomain = parse_url($href, PHP_URL_HOST);
            if ($linkDomain === null || $linkDomain === $domain) {
                $internal++;
            } else {
                $external++;
            }
        });

        return [
            'internal' => $internal,
            'external' => $external,
            'nofollow' => $nofollow,
            'total' => $internal + $external,
        ];
    }

    private function extractOpenGraph(Crawler $crawler): array
    {
        $ogTags = [];
        $crawler->filter('meta[property^="og:"]')->each(function (Crawler $meta) use (&$ogTags) {
            $property = $meta->attr('property');
            $content = $meta->attr('content');
            if ($property && $content) {
                $ogTags[str_replace('og:', '', $property)] = $content;
            }
        });
        return $ogTags;
    }

    private function extractStructuredData(Crawler $crawler): array
    {
        $schemas = [];
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $script) use (&$schemas) {
            try {
                $json = json_decode($script->text(), true);
                if ($json) {
                    $schemas[] = [
                        'type' => $json['@type'] ?? 'Unknown',
                        'data' => $json,
                    ];
                }
            } catch (\Exception $e) {
                // Invalid JSON
            }
        });
        return $schemas;
    }

    private function calculateDepth(string $url, string $baseUrl): int
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $path = trim($path, '/');
        return $path === '' ? 0 : count(explode('/', $path));
    }

    private function makeAbsoluteUrl(?string $href, string $baseUrl): ?string
    {
        if (!$href) {
            return null;
        }

        // Skip non-http links
        if (str_starts_with($href, '#') ||
            str_starts_with($href, 'javascript:') ||
            str_starts_with($href, 'mailto:') ||
            str_starts_with($href, 'tel:')) {
            return null;
        }

        // Already absolute
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        // Make absolute
        $baseParts = parse_url($baseUrl);
        $base = ($baseParts['scheme'] ?? 'https') . '://' . ($baseParts['host'] ?? '');

        if (str_starts_with($href, '/')) {
            return $base . $href;
        }

        $basePath = dirname($baseParts['path'] ?? '/');
        return $base . $basePath . '/' . $href;
    }

    private function isSameDomain(string $url, string $domain): bool
    {
        $urlDomain = parse_url($url, PHP_URL_HOST);
        return $urlDomain === $domain || $urlDomain === 'www.' . $domain || 'www.' . $urlDomain === $domain;
    }

    private function aggregateIssues(array $audit): array
    {
        $issues = [];

        // From robots.txt
        foreach ($audit['technical']['robots_txt']['issues'] ?? [] as $issue) {
            $issues[] = ['type' => str_contains($issue, 'CRITICAL') ? 'critical' : 'warning', 'message' => $issue, 'category' => 'robots'];
        }

        // From sitemap
        foreach ($audit['technical']['sitemap']['issues'] ?? [] as $issue) {
            $issues[] = ['type' => 'warning', 'message' => $issue, 'category' => 'sitemap'];
        }

        // From HTTPS
        foreach ($audit['security']['https']['issues'] ?? [] as $issue) {
            $issues[] = ['type' => 'critical', 'message' => $issue, 'category' => 'security'];
        }

        // From redirects
        foreach ($audit['technical']['redirects']['issues'] ?? [] as $issue) {
            $issues[] = ['type' => 'warning', 'message' => $issue, 'category' => 'redirects'];
        }

        return $issues;
    }

    private function calculateScores(array $audit): array
    {
        $scores = [
            'technical' => 100,
            'seo' => 100,
            'security' => 100,
        ];

        // Deduct points for issues
        foreach ($audit['issues'] as $issue) {
            $deduction = match ($issue['type'] ?? 'info') {
                'critical' => 20,
                'warning' => 10,
                'info' => 5,
                default => 0,
            };

            $category = $issue['category'] ?? 'seo';
            if (in_array($category, ['robots', 'sitemap', 'redirects'])) {
                $scores['technical'] = max(0, $scores['technical'] - $deduction);
            } elseif ($category === 'security') {
                $scores['security'] = max(0, $scores['security'] - $deduction);
            } else {
                $scores['seo'] = max(0, $scores['seo'] - $deduction);
            }
        }

        // Homepage issues
        foreach ($audit['seo']['homepage']['issues'] ?? [] as $issue) {
            $deduction = match ($issue['type'] ?? 'info') {
                'critical' => 15,
                'warning' => 8,
                'info' => 3,
                default => 0,
            };
            $scores['seo'] = max(0, $scores['seo'] - $deduction);
        }

        // Overall score
        $scores['overall'] = round(($scores['technical'] + $scores['seo'] + $scores['security']) / 3);

        return $scores;
    }
}
