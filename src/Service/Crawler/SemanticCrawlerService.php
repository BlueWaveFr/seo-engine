<?php

namespace SeoExpert\Engine\Service\Crawler;

use SeoExpert\Engine\Entity\Project;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SemanticCrawlerService
{
    private const USER_AGENT = 'WaveRankBot/1.0 (+https://waverank.io/bot)';
    private const MAX_PAGES = 30;
    private const TIMEOUT = 10;
    private const RENDERER_URL = 'http://renderer:3003/render';
    private const RENDER_TIMEOUT = 35;
    private const MAX_TEXT_LENGTH = 2000;

    private bool $useRenderer = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        // Check if renderer service is available
        $this->useRenderer = $this->isRendererAvailable();
    }

    /**
     * Check if the headless renderer service is available
     */
    private function isRendererAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', 'http://renderer:3003/health', [
                'timeout' => 2,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            $this->logger->info('Renderer service not available, using direct HTTP crawling');
            return false;
        }
    }

    /**
     * Crawl a website and extract semantic content
     */
    public function crawlWebsite(string $url, int $maxPages = self::MAX_PAGES): array
    {
        $baseUrl = $this->normalizeUrl($url);
        $domain = parse_url($baseUrl, PHP_URL_HOST);

        $result = [
            'baseUrl' => $baseUrl,
            'domain' => $domain,
            'crawledAt' => (new \DateTimeImmutable())->format('c'),
            'pages' => [],
            'sitemapUrls' => [],
            'globalContent' => [
                'allTitles' => [],
                'allHeadings' => [],
                'allMetaDescriptions' => [],
                'allTextContent' => [],
            ],
            'statistics' => [
                'totalPages' => 0,
                'totalWords' => 0,
                'averageWordCount' => 0,
            ],
            'eeat' => [
                'authors' => [],
                'schemaPersons' => [],
                'schemaOrganization' => null,
                'trustSignals' => [],
                'score' => 0,
                'breakdown' => [],
            ],
        ];

        // 1. Try to get URLs from sitemap
        $sitemapUrls = $this->fetchSitemapUrls($baseUrl);
        $result['sitemapUrls'] = $sitemapUrls;

        // 2. Start with homepage + sitemap URLs
        $urlsToCrawl = array_merge([$baseUrl], $sitemapUrls);
        $urlsToCrawl = array_unique($urlsToCrawl);
        $urlsToCrawl = array_slice($urlsToCrawl, 0, $maxPages);

        // 3. Crawl each page
        $crawledUrls = [];
        foreach ($urlsToCrawl as $pageUrl) {
            if (count($crawledUrls) >= $maxPages) {
                break;
            }

            // Skip if already crawled or external
            if (in_array($pageUrl, $crawledUrls)) {
                continue;
            }

            $pageHost = parse_url($pageUrl, PHP_URL_HOST);
            if ($pageHost !== $domain && !str_ends_with($pageHost, '.' . $domain)) {
                continue;
            }

            $pageData = $this->crawlPage($pageUrl);
            if ($pageData) {
                $crawledUrls[] = $pageUrl;

                // Aggregate global content (limit text to save memory)
                if ($pageData['title']) {
                    $result['globalContent']['allTitles'][] = $pageData['title'];
                }
                if ($pageData['metaDescription']) {
                    $result['globalContent']['allMetaDescriptions'][] = $pageData['metaDescription'];
                }
                foreach ($pageData['headings'] as $heading) {
                    $result['globalContent']['allHeadings'][] = $heading;
                }
                if ($pageData['textContent']) {
                    // Limit text content to save memory
                    $result['globalContent']['allTextContent'][] = mb_substr($pageData['textContent'], 0, self::MAX_TEXT_LENGTH);
                }

                // Free memory - don't store full text in page data
                unset($pageData['textContent']);
                $result['pages'][] = $pageData;
            }

            // Small delay to be polite
            usleep(200000); // 200ms
        }

        // 4. Calculate statistics
        $result['statistics']['totalPages'] = count($result['pages']);
        $totalWords = 0;
        foreach ($result['pages'] as $page) {
            $totalWords += $page['wordCount'];
        }
        $result['statistics']['totalWords'] = $totalWords;
        $result['statistics']['averageWordCount'] = $result['statistics']['totalPages'] > 0
            ? round($totalWords / $result['statistics']['totalPages'])
            : 0;

        // 5. Aggregate EEAT data from all pages
        $result['eeat'] = $this->aggregateEeatData($result['pages']);

        return $result;
    }

    /**
     * Crawl a single page and extract content
     */
    private function crawlPage(string $url): ?array
    {
        try {
            // Try to use the JavaScript renderer for SPAs
            if ($this->useRenderer) {
                $html = $this->fetchWithRenderer($url);
                if ($html) {
                    return $this->extractPageContent($url, $html);
                }
            }

            // Fallback to direct HTTP request
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                ],
                'timeout' => self::TIMEOUT,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->info("Skipping {$url}: HTTP {$statusCode}");
                return null;
            }

            $contentType = $response->getHeaders()['content-type'][0] ?? '';
            if (!str_contains($contentType, 'text/html')) {
                return null;
            }

            $html = $response->getContent();
            return $this->extractPageContent($url, $html);

        } catch (\Exception $e) {
            $this->logger->warning("Error crawling {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch page HTML using the headless browser renderer
     */
    private function fetchWithRenderer(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::RENDERER_URL, [
                'query' => ['url' => $url],
                'timeout' => self::RENDER_TIMEOUT,
            ]);

            if ($response->getStatusCode() === 200) {
                $this->logger->debug("Rendered {$url} with headless browser");
                return $response->getContent();
            }
        } catch (\Exception $e) {
            $this->logger->warning("Renderer failed for {$url}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract semantic content from HTML
     */
    private function extractPageContent(string $url, string $html): array
    {
        // Suppress libxml errors
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Extract title
        $title = '';
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        // Extract meta description
        $metaDescription = '';
        $metaNodes = $xpath->query('//meta[@name="description"]/@content');
        if ($metaNodes->length > 0) {
            $metaDescription = trim($metaNodes->item(0)->textContent);
        }

        // Extract meta keywords (if present)
        $metaKeywords = [];
        $keywordNodes = $xpath->query('//meta[@name="keywords"]/@content');
        if ($keywordNodes->length > 0) {
            $keywords = trim($keywordNodes->item(0)->textContent);
            $metaKeywords = array_map('trim', explode(',', $keywords));
        }

        // Extract all headings (h1-h6)
        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $hNodes = $xpath->query("//h{$i}");
            foreach ($hNodes as $node) {
                $text = trim($node->textContent);
                if ($text) {
                    $headings[] = [
                        'level' => $i,
                        'text' => $text,
                    ];
                }
            }
        }

        // Extract main text content
        // Remove scripts, styles, nav, footer, header, aside
        $nodesToRemove = $xpath->query('//script | //style | //nav | //footer | //header | //aside | //noscript | //iframe');
        foreach ($nodesToRemove as $node) {
            $node->parentNode->removeChild($node);
        }

        // Get text from body or main content areas
        $mainContent = '';
        $mainNodes = $xpath->query('//main | //article | //div[@id="content"] | //div[@class="content"] | //div[@role="main"]');
        if ($mainNodes->length > 0) {
            foreach ($mainNodes as $node) {
                $mainContent .= ' ' . $node->textContent;
            }
        } else {
            // Fallback to body
            $bodyNodes = $xpath->query('//body');
            if ($bodyNodes->length > 0) {
                $mainContent = $bodyNodes->item(0)->textContent;
            }
        }

        // Clean up text content
        $textContent = $this->cleanTextContent($mainContent);
        $wordCount = str_word_count($textContent, 0, 'àâäéèêëïîôùûüÿçœæÀÂÄÉÈÊËÏÎÔÙÛÜŸÇŒÆ');

        // Extract internal links
        $internalLinks = [];
        $linkNodes = $xpath->query('//a[@href]');
        $baseHost = parse_url($url, PHP_URL_HOST);
        foreach ($linkNodes as $node) {
            $href = $node->getAttribute('href');
            $linkText = trim($node->textContent);

            // Skip empty links, anchors, javascript
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            // Resolve relative URLs
            $absoluteUrl = $this->resolveUrl($href, $url);
            $linkHost = parse_url($absoluteUrl, PHP_URL_HOST);

            if ($linkHost === $baseHost && $linkText) {
                $internalLinks[] = [
                    'url' => $absoluteUrl,
                    'text' => $linkText,
                ];
            }
        }

        // Extract images with alt text (for context)
        $images = [];
        $imgNodes = $xpath->query('//img[@alt]');
        foreach ($imgNodes as $node) {
            $alt = trim($node->getAttribute('alt'));
            if ($alt && strlen($alt) > 3) {
                $images[] = $alt;
            }
        }

        // Extract EEAT signals
        $eeatData = $this->extractEeatSignals($xpath, $html, $url);

        return [
            'url' => $url,
            'title' => $title,
            'metaDescription' => $metaDescription,
            'metaKeywords' => $metaKeywords,
            'headings' => $headings,
            'textContent' => $textContent,
            'wordCount' => $wordCount,
            'internalLinks' => array_slice($internalLinks, 0, 20), // Limit to 20
            'imageAlts' => array_slice($images, 0, 20),
            'eeat' => $eeatData,
        ];
    }

    /**
     * Extract EEAT (Experience, Expertise, Authoritativeness, Trust) signals from page
     */
    private function extractEeatSignals(\DOMXPath $xpath, string $html, string $url): array
    {
        $eeat = [
            'authors' => [],
            'schemaPersons' => [],
            'schemaOrganization' => null,
            'schemaArticle' => null,
            'trustSignals' => [],
            'isAuthorPage' => false,
            'isAboutPage' => false,
        ];

        // 1. Check if this is an author or about page
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $eeat['isAuthorPage'] = (bool) preg_match('/(author|auteur|profil|team|equipe|expert)/i', $path);
        $eeat['isAboutPage'] = (bool) preg_match('/(about|a-propos|qui-sommes|notre-equipe)/i', $path);

        // 2. Extract Schema.org structured data
        $schemaScripts = $xpath->query('//script[@type="application/ld+json"]');
        foreach ($schemaScripts as $script) {
            $jsonContent = trim($script->textContent);
            if (empty($jsonContent)) continue;

            try {
                $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
                $this->parseSchemaData($data, $eeat);
            } catch (\Exception $e) {
                continue;
            }
        }

        // 3. Extract author info from common HTML patterns
        $authorPatterns = [
            '//a[contains(@class, "author")]',
            '//span[contains(@class, "author")]',
            '//div[contains(@class, "author")]',
            '//*[@rel="author"]',
            '//a[contains(@href, "/author/")]',
            '//a[contains(@href, "/auteur/")]',
            '//meta[@name="author"]/@content',
            '//*[@itemprop="author"]',
        ];

        foreach ($authorPatterns as $pattern) {
            $nodes = $xpath->query($pattern);
            foreach ($nodes as $node) {
                $authorName = '';
                $authorUrl = '';

                if ($node->nodeName === 'a') {
                    $authorName = trim($node->textContent);
                    $authorUrl = $node->getAttribute('href');
                } elseif ($node->nodeName === 'meta' || $node->nodeType === XML_ATTRIBUTE_NODE) {
                    $authorName = trim($node->nodeValue ?? $node->textContent);
                } else {
                    $authorName = trim($node->textContent);
                    // Try to find link inside
                    $links = $xpath->query('.//a', $node);
                    if ($links->length > 0) {
                        $authorUrl = $links->item(0)->getAttribute('href');
                    }
                }

                if ($authorName && strlen($authorName) > 2 && strlen($authorName) < 100) {
                    $eeat['authors'][] = [
                        'name' => $authorName,
                        'url' => $authorUrl ?: null,
                        'source' => 'html',
                    ];
                }
            }
        }

        // Deduplicate authors by name
        $uniqueAuthors = [];
        foreach ($eeat['authors'] as $author) {
            $key = strtolower($author['name']);
            if (!isset($uniqueAuthors[$key])) {
                $uniqueAuthors[$key] = $author;
            } elseif ($author['url'] && !$uniqueAuthors[$key]['url']) {
                $uniqueAuthors[$key]['url'] = $author['url'];
            }
        }
        $eeat['authors'] = array_values($uniqueAuthors);

        // 4. Extract trust signals
        // Social proof links
        $socialLinks = $xpath->query('//a[contains(@href, "linkedin.com") or contains(@href, "twitter.com") or contains(@href, "facebook.com")]');
        if ($socialLinks->length > 0) {
            $eeat['trustSignals'][] = 'social_profiles';
        }

        // Privacy policy, terms, legal mentions
        $legalLinks = $xpath->query('//a[contains(@href, "privacy") or contains(@href, "politique") or contains(@href, "mentions-legales") or contains(@href, "terms") or contains(@href, "conditions")]');
        if ($legalLinks->length > 0) {
            $eeat['trustSignals'][] = 'legal_pages';
        }

        // Contact info
        $contactPatterns = ['//a[contains(@href, "contact")]', '//a[contains(@href, "mailto:")]', '//a[contains(@href, "tel:")]'];
        foreach ($contactPatterns as $pattern) {
            if ($xpath->query($pattern)->length > 0) {
                $eeat['trustSignals'][] = 'contact_info';
                break;
            }
        }

        // HTTPS (already on the page if we're crawling it)
        if (str_starts_with($url, 'https://')) {
            $eeat['trustSignals'][] = 'https';
        }

        // Reviews/testimonials
        $reviewPatterns = $xpath->query('//*[contains(@class, "review") or contains(@class, "testimonial") or contains(@class, "avis")]');
        if ($reviewPatterns->length > 0) {
            $eeat['trustSignals'][] = 'reviews';
        }

        // Certifications/badges
        $certPatterns = $xpath->query('//*[contains(@class, "certif") or contains(@class, "badge") or contains(@class, "trust") or contains(@alt, "certif")]');
        if ($certPatterns->length > 0) {
            $eeat['trustSignals'][] = 'certifications';
        }

        // Publication date
        $datePatterns = [
            '//time[@datetime]',
            '//*[@itemprop="datePublished"]',
            '//*[@itemprop="dateModified"]',
            '//meta[@property="article:published_time"]/@content',
        ];
        foreach ($datePatterns as $pattern) {
            if ($xpath->query($pattern)->length > 0) {
                $eeat['trustSignals'][] = 'publication_date';
                break;
            }
        }

        $eeat['trustSignals'] = array_unique($eeat['trustSignals']);

        return $eeat;
    }

    /**
     * Parse Schema.org JSON-LD data for EEAT signals
     */
    private function parseSchemaData($data, array &$eeat): void
    {
        if (!is_array($data)) return;

        // Handle @graph structure
        if (isset($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                $this->parseSchemaData($item, $eeat);
            }
            return;
        }

        $type = $data['@type'] ?? null;
        if (!$type) return;

        // Normalize type to array
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $t) {
            switch ($t) {
                case 'Person':
                    $person = [
                        'name' => $data['name'] ?? null,
                        'jobTitle' => $data['jobTitle'] ?? null,
                        'description' => $data['description'] ?? null,
                        'url' => $data['url'] ?? null,
                        'image' => is_string($data['image'] ?? null) ? $data['image'] : ($data['image']['url'] ?? null),
                        'sameAs' => $data['sameAs'] ?? [],
                        'worksFor' => $data['worksFor']['name'] ?? ($data['worksFor'] ?? null),
                        'knowsAbout' => $data['knowsAbout'] ?? [],
                        'hasCredential' => $data['hasCredential'] ?? [],
                    ];
                    if ($person['name']) {
                        $eeat['schemaPersons'][] = $person;
                    }
                    break;

                case 'Organization':
                case 'LocalBusiness':
                case 'Corporation':
                    $org = [
                        'name' => $data['name'] ?? null,
                        'description' => $data['description'] ?? null,
                        'url' => $data['url'] ?? null,
                        'logo' => is_string($data['logo'] ?? null) ? $data['logo'] : ($data['logo']['url'] ?? null),
                        'sameAs' => $data['sameAs'] ?? [],
                        'address' => $data['address'] ?? null,
                        'telephone' => $data['telephone'] ?? null,
                        'email' => $data['email'] ?? null,
                        'foundingDate' => $data['foundingDate'] ?? null,
                    ];
                    if ($org['name'] && !$eeat['schemaOrganization']) {
                        $eeat['schemaOrganization'] = $org;
                    }
                    break;

                case 'Article':
                case 'NewsArticle':
                case 'BlogPosting':
                    $article = [
                        'headline' => $data['headline'] ?? null,
                        'datePublished' => $data['datePublished'] ?? null,
                        'dateModified' => $data['dateModified'] ?? null,
                        'author' => null,
                    ];

                    // Extract author from article
                    if (isset($data['author'])) {
                        $authorData = $data['author'];
                        if (is_array($authorData)) {
                            if (isset($authorData['@type'])) {
                                $article['author'] = [
                                    'name' => $authorData['name'] ?? null,
                                    'url' => $authorData['url'] ?? null,
                                ];
                            } elseif (isset($authorData[0])) {
                                // Array of authors
                                $article['author'] = [
                                    'name' => $authorData[0]['name'] ?? null,
                                    'url' => $authorData[0]['url'] ?? null,
                                ];
                            }
                        } elseif (is_string($authorData)) {
                            $article['author'] = ['name' => $authorData, 'url' => null];
                        }

                        if ($article['author'] && $article['author']['name']) {
                            $eeat['authors'][] = array_merge($article['author'], ['source' => 'schema']);
                        }
                    }

                    $eeat['schemaArticle'] = $article;
                    break;
            }
        }
    }

    /**
     * Aggregate EEAT data from all crawled pages
     */
    private function aggregateEeatData(array $pages): array
    {
        $aggregated = [
            'authors' => [],
            'schemaPersons' => [],
            'schemaOrganization' => null,
            'trustSignals' => [],
            'authorPages' => [],
            'aboutPages' => [],
            'score' => 0,
            'breakdown' => [
                'experience' => 0,
                'expertise' => 0,
                'authoritativeness' => 0,
                'trust' => 0,
            ],
            'recommendations' => [],
        ];

        $allTrustSignals = [];
        $authorsByName = [];

        foreach ($pages as $page) {
            if (!isset($page['eeat'])) continue;

            $eeat = $page['eeat'];

            // Collect authors
            foreach ($eeat['authors'] ?? [] as $author) {
                $key = strtolower($author['name']);
                if (!isset($authorsByName[$key])) {
                    $authorsByName[$key] = $author;
                    $authorsByName[$key]['pages'] = 1;
                } else {
                    $authorsByName[$key]['pages']++;
                    if ($author['url'] && !$authorsByName[$key]['url']) {
                        $authorsByName[$key]['url'] = $author['url'];
                    }
                }
            }

            // Collect Schema.org Persons
            foreach ($eeat['schemaPersons'] ?? [] as $person) {
                if ($person['name']) {
                    $found = false;
                    foreach ($aggregated['schemaPersons'] as &$existing) {
                        if (strtolower($existing['name']) === strtolower($person['name'])) {
                            // Merge data
                            $existing = array_merge($existing, array_filter($person));
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $aggregated['schemaPersons'][] = $person;
                    }
                }
            }

            // Keep first organization found
            if (!$aggregated['schemaOrganization'] && ($eeat['schemaOrganization'] ?? null)) {
                $aggregated['schemaOrganization'] = $eeat['schemaOrganization'];
            }

            // Collect trust signals
            $allTrustSignals = array_merge($allTrustSignals, $eeat['trustSignals'] ?? []);

            // Track author/about pages
            if ($eeat['isAuthorPage'] ?? false) {
                $aggregated['authorPages'][] = $page['url'];
            }
            if ($eeat['isAboutPage'] ?? false) {
                $aggregated['aboutPages'][] = $page['url'];
            }
        }

        $aggregated['authors'] = array_values($authorsByName);
        $aggregated['trustSignals'] = array_values(array_unique($allTrustSignals));

        // Calculate EEAT score
        $this->calculateEeatScore($aggregated);

        return $aggregated;
    }

    /**
     * Calculate overall EEAT score and generate recommendations
     */
    private function calculateEeatScore(array &$eeat): void
    {
        $breakdown = [
            'experience' => 0,
            'expertise' => 0,
            'authoritativeness' => 0,
            'trust' => 0,
        ];
        $recommendations = [];

        // Experience (25 points max)
        // - Authors with dedicated pages
        // - Publication dates on content
        // - Multiple authors
        if (count($eeat['authorPages']) > 0) {
            $breakdown['experience'] += 10;
        } else {
            $recommendations[] = 'Creez des pages auteurs dediees pour presenter l\'experience de vos redacteurs';
        }

        if (in_array('publication_date', $eeat['trustSignals'])) {
            $breakdown['experience'] += 5;
        } else {
            $recommendations[] = 'Ajoutez des dates de publication et de mise a jour sur vos articles';
        }

        if (count($eeat['authors']) >= 2) {
            $breakdown['experience'] += 5;
        }

        if (count($eeat['authors']) > 0) {
            $breakdown['experience'] += 5;
        } else {
            $recommendations[] = 'Identifiez clairement les auteurs de vos contenus';
        }

        // Expertise (25 points max)
        // - Schema.org Person with credentials
        // - Job titles mentioned
        // - About page exists
        $hasCredentials = false;
        $hasJobTitle = false;
        foreach ($eeat['schemaPersons'] as $person) {
            if (!empty($person['hasCredential']) || !empty($person['knowsAbout'])) {
                $hasCredentials = true;
            }
            if (!empty($person['jobTitle'])) {
                $hasJobTitle = true;
            }
        }

        if ($hasCredentials) {
            $breakdown['expertise'] += 10;
        } else {
            $recommendations[] = 'Ajoutez les qualifications et certifications de vos auteurs dans le Schema.org';
        }

        if ($hasJobTitle) {
            $breakdown['expertise'] += 5;
        }

        if (count($eeat['aboutPages']) > 0) {
            $breakdown['expertise'] += 5;
        } else {
            $recommendations[] = 'Creez une page "A propos" ou "Notre equipe" pour presenter votre expertise';
        }

        if (count($eeat['schemaPersons']) > 0) {
            $breakdown['expertise'] += 5;
        } else {
            $recommendations[] = 'Implementez le Schema.org Person pour vos auteurs';
        }

        // Authoritativeness (25 points max)
        // - Social profiles linked
        // - Organization schema
        // - External references (sameAs)
        if (in_array('social_profiles', $eeat['trustSignals'])) {
            $breakdown['authoritativeness'] += 8;
        } else {
            $recommendations[] = 'Liez vos profils LinkedIn, Twitter et autres reseaux sociaux';
        }

        if ($eeat['schemaOrganization']) {
            $breakdown['authoritativeness'] += 7;
            if (!empty($eeat['schemaOrganization']['sameAs'])) {
                $breakdown['authoritativeness'] += 5;
            }
        } else {
            $recommendations[] = 'Ajoutez le Schema.org Organization pour votre entreprise';
        }

        $hasSameAs = false;
        foreach ($eeat['schemaPersons'] as $person) {
            if (!empty($person['sameAs'])) {
                $hasSameAs = true;
                break;
            }
        }
        if ($hasSameAs) {
            $breakdown['authoritativeness'] += 5;
        }

        // Trust (25 points max)
        // - HTTPS
        // - Legal pages
        // - Contact info
        // - Reviews
        // - Certifications
        if (in_array('https', $eeat['trustSignals'])) {
            $breakdown['trust'] += 5;
        }

        if (in_array('legal_pages', $eeat['trustSignals'])) {
            $breakdown['trust'] += 5;
        } else {
            $recommendations[] = 'Ajoutez des liens vers vos mentions legales et politique de confidentialite';
        }

        if (in_array('contact_info', $eeat['trustSignals'])) {
            $breakdown['trust'] += 5;
        } else {
            $recommendations[] = 'Rendez vos coordonnees de contact facilement accessibles';
        }

        if (in_array('reviews', $eeat['trustSignals'])) {
            $breakdown['trust'] += 5;
        }

        if (in_array('certifications', $eeat['trustSignals'])) {
            $breakdown['trust'] += 5;
        }

        // Calculate total score
        $totalScore = $breakdown['experience'] + $breakdown['expertise'] + $breakdown['authoritativeness'] + $breakdown['trust'];

        $eeat['score'] = $totalScore;
        $eeat['breakdown'] = $breakdown;
        $eeat['recommendations'] = array_slice($recommendations, 0, 5); // Top 5 recommendations
    }

    /**
     * Fetch URLs from sitemap.xml
     */
    private function fetchSitemapUrls(string $baseUrl): array
    {
        $sitemapUrls = [
            rtrim($baseUrl, '/') . '/sitemap.xml',
            rtrim($baseUrl, '/') . '/sitemap_index.xml',
            rtrim($baseUrl, '/') . '/sitemap-index.xml',
        ];

        $urls = [];

        foreach ($sitemapUrls as $sitemapUrl) {
            try {
                $response = $this->httpClient->request('GET', $sitemapUrl, [
                    'headers' => ['User-Agent' => self::USER_AGENT],
                    'timeout' => self::TIMEOUT,
                ]);

                if ($response->getStatusCode() !== 200) {
                    continue;
                }

                $content = $response->getContent();
                $urls = array_merge($urls, $this->parseSitemap($content, $baseUrl));

                if (!empty($urls)) {
                    break; // Found a valid sitemap
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Try robots.txt for sitemap location
        if (empty($urls)) {
            $urls = $this->fetchSitemapFromRobots($baseUrl);
        }

        return array_unique($urls);
    }

    /**
     * Parse sitemap XML content
     */
    private function parseSitemap(string $content, string $baseUrl, int $depth = 0): array
    {
        $urls = [];

        // Prevent infinite recursion
        if ($depth > 3) {
            $this->logger->warning("Sitemap recursion limit reached");
            return $urls;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            return $urls;
        }

        // Check if it's a sitemap index
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                $sitemapLoc = (string) $sitemap->loc;
                try {
                    $response = $this->httpClient->request('GET', $sitemapLoc, [
                        'headers' => ['User-Agent' => self::USER_AGENT],
                        'timeout' => self::TIMEOUT,
                    ]);
                    if ($response->getStatusCode() === 200) {
                        $urls = array_merge($urls, $this->parseSitemap($response->getContent(), $baseUrl, $depth + 1));
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        // Regular sitemap with urls
        if (isset($xml->url)) {
            foreach ($xml->url as $urlEntry) {
                $loc = (string) $urlEntry->loc;
                if ($loc) {
                    $urls[] = $loc;
                }
            }
        }

        return $urls;
    }

    /**
     * Try to find sitemap URL in robots.txt
     */
    private function fetchSitemapFromRobots(string $baseUrl): array
    {
        $urls = [];
        $robotsUrl = rtrim($baseUrl, '/') . '/robots.txt';

        try {
            $response = $this->httpClient->request('GET', $robotsUrl, [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => self::TIMEOUT,
            ]);

            if ($response->getStatusCode() === 200) {
                $content = $response->getContent();

                // Find Sitemap directives
                if (preg_match_all('/Sitemap:\s*(.+)/i', $content, $matches)) {
                    foreach ($matches[1] as $sitemapUrl) {
                        $sitemapUrl = trim($sitemapUrl);
                        $urls = array_merge($urls, $this->parseSitemap(
                            $this->httpClient->request('GET', $sitemapUrl, [
                                'headers' => ['User-Agent' => self::USER_AGENT],
                                'timeout' => self::TIMEOUT,
                            ])->getContent(),
                            $baseUrl
                        ));
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $urls;
    }

    /**
     * Clean and normalize text content
     */
    private function cleanTextContent(string $text): string
    {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Remove common non-content patterns
        $text = preg_replace('/Cookie\s*(?:Policy|Settings|Consent).*?(?:Accept|Decline|Close)/i', '', $text);
        $text = preg_replace('/©\s*\d{4}.*?(?:All Rights Reserved|Tous droits)/i', '', $text);

        return trim($text);
    }

    /**
     * Normalize URL (ensure https, remove trailing slash)
     */
    private function normalizeUrl(string $url): string
    {
        // Add scheme if missing
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        // Parse and rebuild
        $parts = parse_url($url);
        $normalized = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        if (isset($parts['port']) && $parts['port'] !== 80 && $parts['port'] !== 443) {
            $normalized .= ':' . $parts['port'];
        }

        if (isset($parts['path']) && $parts['path'] !== '/') {
            $normalized .= rtrim($parts['path'], '/');
        }

        return $normalized;
    }

    /**
     * Resolve relative URL to absolute
     */
    private function resolveUrl(string $href, string $baseUrl): string
    {
        // Already absolute
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        // Root-relative
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        // Relative
        $basePath = $base['path'] ?? '/';
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        return $scheme . '://' . $host . $basePath . $href;
    }
}
