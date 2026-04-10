<?php

namespace SeoExpert\Engine\Service\Eeat;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * E-E-A-T Pro Analyzer — 25+ weighted signals across 4 pillars.
 *
 * This is the enhanced version of EeatLightService.
 * It can analyze single pages AND aggregate across multiple pages.
 */
class EeatAnalyzerService
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; WaveRankBot/2.0; +https://waverank.io)';

    private const TRUSTED_DOMAINS = [
        // Government
        '.gouv.fr', '.gov', '.gov.uk', '.gc.ca', '.gob.es', '.bund.de',
        // Education
        '.edu', '.ac.uk', '.ac.fr', '.edu.au',
        // Institutions
        'wikipedia.org', 'who.int', 'insee.fr', 'legifrance.gouv.fr',
        'europa.eu', 'nih.gov', 'cdc.gov', 'pubmed.ncbi.nlm.nih.gov',
        'scholar.google.com', 'nature.com', 'science.org', 'sciencedirect.com',
        'banque-france.fr', 'ecb.europa.eu', 'amf-france.org',
        'w3.org', 'ietf.org', 'iso.org',
    ];

    private const REVIEW_PLATFORMS = [
        'trustpilot.com', 'google.com/maps', 'g2.com', 'capterra.com',
        'avis-verifies.com', 'tripadvisor.com', 'yelp.com', 'glassdoor.com',
        'clutch.co', 'goodfirms.co', 'sortlist.com',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Full E-E-A-T analysis for a URL.
     * Returns everything needed for an EeatSnapshot.
     */
    public function analyze(string $url, array $options = []): array
    {
        $startTime = microtime(true);

        $html = $this->fetchHtml($url);
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($doc);

        // Extract all raw signals
        $signals = $this->extractAllSignals($xpath, $html, $url);

        // Calculate enhanced scores
        $scoring = $this->calculateEnhancedScore($signals);

        // Calculate AI citability score
        $citability = $this->calculateCitabilityScore($signals, $scoring);

        // Generate prioritized recommendations
        $recommendations = $this->generateSmartRecommendations($signals, $scoring, $citability);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            // Scores
            'eeat_score' => $scoring['total'],
            'experience_score' => $scoring['breakdown']['experience'],
            'expertise_score' => $scoring['breakdown']['expertise'],
            'authority_score' => $scoring['breakdown']['authoritativeness'],
            'trust_score' => $scoring['breakdown']['trust'],
            'ai_citability_score' => $citability['score'],

            // Signals
            'trust_signals' => $signals['trustSignals'],
            'authors' => $signals['authors'],
            'schema_data' => [
                'organization' => $signals['schemaOrganization'],
                'persons' => $signals['schemaPersons'],
                'article' => $signals['schemaArticle'],
                'has_faq' => $signals['hasFaqSchema'],
                'has_howto' => $signals['hasHowToSchema'],
                'has_breadcrumb' => $signals['hasBreadcrumb'],
            ],
            'signal_details' => $scoring['signal_details'],
            'citability_breakdown' => $citability['breakdown'],
            'content_freshness' => $signals['contentFreshness'],
            'content_metrics' => [
                'word_count' => $signals['wordCount'],
                'external_links' => $signals['externalLinksCount'],
                'trusted_links' => count($signals['trustedOutboundLinks']),
                'internal_links' => $signals['internalLinksCount'],
                'images_count' => $signals['imagesCount'],
                'images_with_alt' => $signals['imagesWithAlt'],
                'headings_structure' => $signals['headingsStructure'],
            ],
            'recommendations' => $recommendations,

            // Meta
            'url' => $url,
            'pages_crawled' => 1,
            'duration_ms' => $durationMs,
            'analyzed_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Analyze multiple pages and aggregate (for project-level E-E-A-T).
     */
    public function analyzeMultiplePages(array $urls, string $mainUrl): array
    {
        $allSignals = [];
        $errors = [];

        foreach ($urls as $url) {
            try {
                $allSignals[] = $this->analyzeSinglePageSignals($url);
            } catch (\Exception $e) {
                $errors[] = ['url' => $url, 'error' => $e->getMessage()];
                $this->logger->warning('EEAT: Failed to analyze {url}: {error}', [
                    'url' => $url, 'error' => $e->getMessage()
                ]);
            }
        }

        if (empty($allSignals)) {
            throw new \RuntimeException('No pages could be analyzed');
        }

        $aggregated = $this->aggregateSignals($allSignals, $mainUrl);
        $scoring = $this->calculateEnhancedScore($aggregated);
        $citability = $this->calculateCitabilityScore($aggregated, $scoring);
        $recommendations = $this->generateSmartRecommendations($aggregated, $scoring, $citability);

        return [
            'eeat_score' => $scoring['total'],
            'experience_score' => $scoring['breakdown']['experience'],
            'expertise_score' => $scoring['breakdown']['expertise'],
            'authority_score' => $scoring['breakdown']['authoritativeness'],
            'trust_score' => $scoring['breakdown']['trust'],
            'ai_citability_score' => $citability['score'],
            'trust_signals' => $aggregated['trustSignals'],
            'authors' => $aggregated['authors'],
            'schema_data' => [
                'organization' => $aggregated['schemaOrganization'],
                'persons' => $aggregated['schemaPersons'],
            ],
            'signal_details' => $scoring['signal_details'],
            'citability_breakdown' => $citability['breakdown'],
            'content_freshness' => $aggregated['contentFreshness'],
            'recommendations' => $recommendations,
            'url' => $mainUrl,
            'pages_crawled' => count($allSignals),
            'errors' => $errors,
            'analyzed_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    // ─── Signal extraction ───────────────────────────────────────────────

    private function analyzeSinglePageSignals(string $url): array
    {
        $html = $this->fetchHtml($url);
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($doc);

        return $this->extractAllSignals($xpath, $html, $url);
    }

    private function extractAllSignals(\DOMXPath $xpath, string $html, string $url): array
    {
        $signals = [
            'url' => $url,
            'authors' => [],
            'schemaPersons' => [],
            'schemaOrganization' => null,
            'schemaArticle' => null,
            'hasFaqSchema' => false,
            'hasHowToSchema' => false,
            'hasBreadcrumb' => false,
            'trustSignals' => [],
            'trustedOutboundLinks' => [],
            'reviewPlatformLinks' => [],
            'editorialPolicyLinks' => [],
            'contentDates' => [],
            'externalLinksCount' => 0,
            'internalLinksCount' => 0,
            'wordCount' => 0,
            'imagesCount' => 0,
            'imagesWithAlt' => 0,
            'headingsStructure' => [],
            'contentFreshness' => 'unknown',
            'hasAboutPage' => false,
            'hasAuthorPages' => false,
            'hasLlmsTxt' => false,
            'hasStructuredContact' => false,
            'hasPriceTransparency' => false,
            'hasAccessibility' => false,
        ];

        // 1. Schema.org structured data
        $this->extractSchemaData($xpath, $signals);

        // 2. Author info
        $this->extractAuthors($xpath, $signals);

        // 3. Trust signals
        $this->extractTrustSignals($xpath, $html, $url, $signals);

        // 4. Content quality signals
        $this->extractContentMetrics($xpath, $html, $signals);

        // 5. Links analysis
        $this->extractLinkSignals($xpath, $url, $signals);

        // 6. Content freshness
        $signals['contentFreshness'] = $this->evaluateContentFreshness($signals['contentDates']);

        // 7. Advanced signals
        $this->extractAdvancedSignals($xpath, $html, $url, $signals);

        return $signals;
    }

    private function extractSchemaData(\DOMXPath $xpath, array &$signals): void
    {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        foreach ($scripts as $script) {
            $json = trim($script->textContent);
            if (empty($json)) continue;
            try {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $this->parseSchemaRecursive($data, $signals);
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    private function parseSchemaRecursive(mixed $data, array &$signals): void
    {
        if (!is_array($data)) return;

        if (isset($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                $this->parseSchemaRecursive($item, $signals);
            }
            return;
        }

        $type = $data['@type'] ?? null;
        if (!$type) return;
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $t) {
            match ($t) {
                'Person' => $this->parsePersonSchema($data, $signals),
                'Organization', 'LocalBusiness', 'Corporation' => $this->parseOrgSchema($data, $signals),
                'Article', 'NewsArticle', 'BlogPosting', 'TechArticle' => $this->parseArticleSchema($data, $signals),
                'FAQPage' => $signals['hasFaqSchema'] = true,
                'HowTo' => $signals['hasHowToSchema'] = true,
                'BreadcrumbList' => $signals['hasBreadcrumb'] = true,
                default => null,
            };
        }
    }

    private function parsePersonSchema(array $data, array &$signals): void
    {
        $person = [
            'name' => $data['name'] ?? null,
            'jobTitle' => $data['jobTitle'] ?? null,
            'description' => $data['description'] ?? null,
            'url' => $data['url'] ?? null,
            'image' => is_string($data['image'] ?? null) ? $data['image'] : ($data['image']['url'] ?? null),
            'sameAs' => $data['sameAs'] ?? [],
            'worksFor' => is_string($data['worksFor'] ?? null) ? $data['worksFor'] : ($data['worksFor']['name'] ?? null),
            'knowsAbout' => $data['knowsAbout'] ?? [],
            'hasCredential' => $data['hasCredential'] ?? [],
            'alumniOf' => $data['alumniOf']['name'] ?? ($data['alumniOf'] ?? null),
        ];
        if ($person['name']) {
            $signals['schemaPersons'][] = $person;
        }
    }

    private function parseOrgSchema(array $data, array &$signals): void
    {
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
            'numberOfEmployees' => $data['numberOfEmployees'] ?? null,
            'areaServed' => $data['areaServed'] ?? null,
        ];
        if ($org['name'] && !$signals['schemaOrganization']) {
            $signals['schemaOrganization'] = $org;
        }
    }

    private function parseArticleSchema(array $data, array &$signals): void
    {
        $article = [
            'headline' => $data['headline'] ?? null,
            'datePublished' => $data['datePublished'] ?? null,
            'dateModified' => $data['dateModified'] ?? null,
            'author' => null,
            'wordCount' => $data['wordCount'] ?? null,
        ];

        if (isset($data['author'])) {
            $author = is_array($data['author'])
                ? ($data['author']['name'] ?? ($data['author'][0]['name'] ?? null))
                : $data['author'];
            $authorUrl = is_array($data['author'])
                ? ($data['author']['url'] ?? ($data['author'][0]['url'] ?? null))
                : null;

            if ($author) {
                $article['author'] = ['name' => $author, 'url' => $authorUrl];
                $signals['authors'][] = ['name' => $author, 'url' => $authorUrl, 'source' => 'schema'];
            }
        }

        $signals['schemaArticle'] = $article;
    }

    private function extractAuthors(\DOMXPath $xpath, array &$signals): void
    {
        $patterns = [
            '//a[contains(@class, "author")]',
            '//span[contains(@class, "author")]',
            '//div[contains(@class, "author")]',
            '//*[@rel="author"]',
            '//a[contains(@href, "/author/")]',
            '//a[contains(@href, "/auteur/")]',
            '//meta[@name="author"]/@content',
            '//*[@itemprop="author"]',
        ];

        foreach ($patterns as $pattern) {
            $nodes = $xpath->query($pattern);
            foreach ($nodes as $node) {
                $name = '';
                $url = '';

                if ($node->nodeName === 'a') {
                    $name = trim($node->textContent);
                    $url = $node->getAttribute('href');
                } elseif ($node->nodeName === 'meta' || $node->nodeType === XML_ATTRIBUTE_NODE) {
                    $name = trim($node->nodeValue ?? $node->textContent);
                } else {
                    $name = trim($node->textContent);
                    $links = $xpath->query('.//a', $node);
                    if ($links->length > 0) {
                        $url = $links->item(0)->getAttribute('href');
                    }
                }

                if ($name && strlen($name) > 2 && strlen($name) < 100) {
                    $signals['authors'][] = ['name' => $name, 'url' => $url ?: null, 'source' => 'html'];
                }
            }
        }

        // Deduplicate
        $unique = [];
        foreach ($signals['authors'] as $author) {
            $key = strtolower($author['name']);
            if (!isset($unique[$key])) {
                $unique[$key] = $author;
            } elseif ($author['url'] && !$unique[$key]['url']) {
                $unique[$key]['url'] = $author['url'];
            }
        }
        $signals['authors'] = array_values($unique);
    }

    private function extractTrustSignals(\DOMXPath $xpath, string $html, string $url, array &$signals): void
    {
        // HTTPS
        if (str_starts_with($url, 'https://')) {
            $signals['trustSignals'][] = 'https';
        }

        // Social profiles
        if ($xpath->query('//a[contains(@href, "linkedin.com") or contains(@href, "twitter.com") or contains(@href, "x.com") or contains(@href, "facebook.com") or contains(@href, "instagram.com")]')->length > 0) {
            $signals['trustSignals'][] = 'social_profiles';
        }

        // Legal pages
        if ($xpath->query('//a[contains(@href, "privacy") or contains(@href, "politique") or contains(@href, "mentions-legales") or contains(@href, "terms") or contains(@href, "conditions") or contains(@href, "rgpd") or contains(@href, "gdpr") or contains(@href, "legal")]')->length > 0) {
            $signals['trustSignals'][] = 'legal_pages';
        }

        // Contact info
        $contactPatterns = ['//a[contains(@href, "contact")]', '//a[contains(@href, "mailto:")]', '//a[contains(@href, "tel:")]'];
        foreach ($contactPatterns as $pattern) {
            if ($xpath->query($pattern)->length > 0) {
                $signals['trustSignals'][] = 'contact_info';
                break;
            }
        }

        // Structured contact (address in schema)
        if (!empty($signals['schemaOrganization']['telephone']) || !empty($signals['schemaOrganization']['address'])) {
            $signals['trustSignals'][] = 'structured_contact';
            $signals['hasStructuredContact'] = true;
        }

        // Reviews / testimonials
        if ($xpath->query('//*[contains(@class, "review") or contains(@class, "testimonial") or contains(@class, "avis") or contains(@class, "temoignage")]')->length > 0) {
            $signals['trustSignals'][] = 'reviews';
        }

        // Certifications / badges
        if ($xpath->query('//*[contains(@class, "certif") or contains(@class, "badge") or contains(@class, "trust") or contains(@alt, "certif") or contains(@alt, "label")]')->length > 0) {
            $signals['trustSignals'][] = 'certifications';
        }

        // Publication dates
        $datePatterns = ['//time[@datetime]', '//*[@itemprop="datePublished"]', '//*[@itemprop="dateModified"]', '//meta[@property="article:published_time"]/@content'];
        foreach ($datePatterns as $pattern) {
            if ($xpath->query($pattern)->length > 0) {
                $signals['trustSignals'][] = 'publication_date';
                break;
            }
        }

        // Date extraction
        $timeElements = $xpath->query('//time[@datetime]');
        foreach ($timeElements as $time) {
            $dt = $time->getAttribute('datetime');
            if ($dt) $signals['contentDates'][] = $dt;
        }
        $dateMetas = $xpath->query('//meta[@property="article:published_time" or @property="article:modified_time"]/@content');
        foreach ($dateMetas as $meta) {
            if ($meta->nodeValue) $signals['contentDates'][] = $meta->nodeValue;
        }
        if (!empty($signals['schemaArticle']['dateModified'])) {
            $signals['contentDates'][] = $signals['schemaArticle']['dateModified'];
        } elseif (!empty($signals['schemaArticle']['datePublished'])) {
            $signals['contentDates'][] = $signals['schemaArticle']['datePublished'];
        }

        // Third-party reviews
        $allLinks = $xpath->query('//a[@href]');
        foreach ($allLinks as $link) {
            $href = $link->getAttribute('href');
            foreach (self::REVIEW_PLATFORMS as $platform) {
                if (str_contains($href, $platform)) {
                    $signals['reviewPlatformLinks'][] = $href;
                    $signals['trustSignals'][] = 'third_party_reviews';
                    break;
                }
            }
        }

        // Review widgets
        if ($xpath->query('//*[contains(@class, "trustpilot") or contains(@id, "trustpilot") or contains(@class, "google-reviews") or contains(@class, "avis-verifies")]')->length > 0) {
            $signals['trustSignals'][] = 'third_party_reviews';
        }

        // Editorial policy
        $editLinks = $xpath->query('//a[contains(@href, "charte") or contains(@href, "editorial-policy") or contains(@href, "fact-checking") or contains(@href, "methodologie") or contains(@href, "methodology") or contains(@href, "correction")]');
        foreach ($editLinks as $link) {
            $signals['editorialPolicyLinks'][] = $link->getAttribute('href');
        }
        if (count($signals['editorialPolicyLinks']) > 0) {
            $signals['trustSignals'][] = 'editorial_policy';
        }

        // Price transparency
        if ($xpath->query('//a[contains(@href, "tarif") or contains(@href, "pricing") or contains(@href, "prix")]')->length > 0
            || $xpath->query('//*[contains(@class, "pricing") or contains(@class, "tarif")]')->length > 0) {
            $signals['trustSignals'][] = 'price_transparency';
            $signals['hasPriceTransparency'] = true;
        }

        // Accessibility statement
        if ($xpath->query('//a[contains(@href, "accessibilite") or contains(@href, "accessibility")]')->length > 0) {
            $signals['trustSignals'][] = 'accessibility';
            $signals['hasAccessibility'] = true;
        }

        // Cookie consent
        if ($xpath->query('//*[contains(@class, "cookie") or contains(@id, "cookie") or contains(@id, "consent") or contains(@class, "consent")]')->length > 0) {
            $signals['trustSignals'][] = 'cookie_consent';
        }

        $signals['trustSignals'] = array_values(array_unique($signals['trustSignals']));
        $signals['reviewPlatformLinks'] = array_values(array_unique($signals['reviewPlatformLinks']));
        $signals['editorialPolicyLinks'] = array_values(array_unique($signals['editorialPolicyLinks']));
    }

    private function extractContentMetrics(\DOMXPath $xpath, string $html, array &$signals): void
    {
        // Word count
        $bodyNodes = $xpath->query('//body');
        if ($bodyNodes->length > 0) {
            $text = $bodyNodes->item(0)->textContent;
            $text = preg_replace('/\s+/', ' ', $text);
            $signals['wordCount'] = str_word_count($text);
        }

        // Images
        $images = $xpath->query('//img');
        $signals['imagesCount'] = $images->length;
        $withAlt = 0;
        foreach ($images as $img) {
            if (trim($img->getAttribute('alt')) !== '') {
                $withAlt++;
            }
        }
        $signals['imagesWithAlt'] = $withAlt;

        // Headings structure
        for ($i = 1; $i <= 6; $i++) {
            $count = $xpath->query("//h{$i}")->length;
            if ($count > 0) {
                $signals['headingsStructure']["h{$i}"] = $count;
            }
        }
    }

    private function extractLinkSignals(\DOMXPath $xpath, string $url, array &$signals): void
    {
        $currentDomain = parse_url($url, PHP_URL_HOST);
        $allLinks = $xpath->query('//a[@href]');

        foreach ($allLinks as $link) {
            $href = $link->getAttribute('href');
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) continue;

            $linkHost = parse_url($href, PHP_URL_HOST);

            // Internal link
            if (!$linkHost || $linkHost === $currentDomain || str_ends_with($linkHost, '.' . $currentDomain)) {
                $signals['internalLinksCount']++;

                // Check for about/author pages
                if (preg_match('#/(about|a-propos|qui-sommes-nous|team|equipe)#i', $href)) {
                    $signals['hasAboutPage'] = true;
                }
                if (preg_match('#/(author|auteur)#i', $href)) {
                    $signals['hasAuthorPages'] = true;
                }

                continue;
            }

            // External link
            $signals['externalLinksCount']++;

            // Trusted outbound
            foreach (self::TRUSTED_DOMAINS as $trusted) {
                if (str_ends_with($linkHost, $trusted) || $linkHost === ltrim($trusted, '.')) {
                    $signals['trustedOutboundLinks'][] = $href;
                    break;
                }
            }
        }

        $signals['trustedOutboundLinks'] = array_values(array_unique($signals['trustedOutboundLinks']));
    }

    private function extractAdvancedSignals(\DOMXPath $xpath, string $html, string $url, array &$signals): void
    {
        // llms.txt detection
        try {
            $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
            $response = $this->httpClient->request('HEAD', $baseUrl . '/llms.txt', [
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => 3,
                'max_redirects' => 1,
            ]);
            if ($response->getStatusCode() === 200) {
                $signals['hasLlmsTxt'] = true;
                $signals['trustSignals'][] = 'llms_txt';
            }
        } catch (\Exception $e) {
            // Ignore
        }

        // robots.txt check for AI bots blocking
        // Hreflang (international)
        $hreflangs = $xpath->query('//link[@rel="alternate"][@hreflang]');
        if ($hreflangs->length > 0) {
            $signals['trustSignals'][] = 'hreflang';
        }

        // Canonical tag
        $canonical = $xpath->query('//link[@rel="canonical"]');
        if ($canonical->length > 0) {
            $signals['trustSignals'][] = 'canonical';
        }

        $signals['trustSignals'] = array_values(array_unique($signals['trustSignals']));
    }

    // ─── Enhanced Scoring (25+ signals) ──────────────────────────────────

    private function calculateEnhancedScore(array $signals): array
    {
        $details = [];
        $raw = ['experience' => 0, 'expertise' => 0, 'authoritativeness' => 0, 'trust' => 0];
        $max = ['experience' => 0, 'expertise' => 0, 'authoritativeness' => 0, 'trust' => 0];

        // ═══ EXPERIENCE (7 signals) ═══

        $this->scoreSignal($details, $raw, $max, 'experience', 'author_identified',
            'Auteur(s) clairement identifie(s)', count($signals['authors']) > 0, 8);

        $this->scoreSignal($details, $raw, $max, 'experience', 'multiple_authors',
            'Plusieurs auteurs contribuent', count($signals['authors']) >= 2, 3);

        $this->scoreSignal($details, $raw, $max, 'experience', 'author_pages',
            'Pages auteur dediees', $signals['hasAuthorPages'] ?? false, 5);

        $this->scoreSignal($details, $raw, $max, 'experience', 'publication_dates',
            'Dates de publication presentes', in_array('publication_date', $signals['trustSignals']), 6);

        $freshness = $signals['contentFreshness'] ?? 'unknown';
        $this->scoreSignal($details, $raw, $max, 'experience', 'content_fresh',
            'Contenu mis a jour recemment', $freshness === 'fresh', 5,
            $freshness === 'recent' ? 3 : 0);

        $this->scoreSignal($details, $raw, $max, 'experience', 'about_page',
            'Page A propos / Qui sommes-nous', $signals['hasAboutPage'] ?? false, 4);

        $this->scoreSignal($details, $raw, $max, 'experience', 'case_studies',
            'Temoignages ou etudes de cas', in_array('reviews', $signals['trustSignals']), 4);

        // ═══ EXPERTISE (7 signals) ═══

        $hasCredentials = false;
        $hasJobTitle = false;
        $hasKnowsAbout = false;
        foreach ($signals['schemaPersons'] ?? [] as $person) {
            if (!empty($person['hasCredential'])) $hasCredentials = true;
            if (!empty($person['knowsAbout'])) $hasKnowsAbout = true;
            if (!empty($person['jobTitle'])) $hasJobTitle = true;
        }

        $this->scoreSignal($details, $raw, $max, 'expertise', 'schema_person',
            'Schema.org Person pour les auteurs', count($signals['schemaPersons'] ?? []) > 0, 6);

        $this->scoreSignal($details, $raw, $max, 'expertise', 'author_credentials',
            'Qualifications et certifications auteur', $hasCredentials, 7);

        $this->scoreSignal($details, $raw, $max, 'expertise', 'author_jobtitle',
            'Titre de poste de l\'auteur', $hasJobTitle, 4);

        $this->scoreSignal($details, $raw, $max, 'expertise', 'author_expertise',
            'Domaines d\'expertise declares (knowsAbout)', $hasKnowsAbout, 5);

        $trustedCount = count($signals['trustedOutboundLinks'] ?? []);
        $this->scoreSignal($details, $raw, $max, 'expertise', 'trusted_sources',
            'Liens vers sources institutionnelles', $trustedCount >= 2, 6,
            $trustedCount === 1 ? 3 : 0);

        $this->scoreSignal($details, $raw, $max, 'expertise', 'content_depth',
            'Profondeur du contenu (>1000 mots)', ($signals['wordCount'] ?? 0) >= 1000, 4,
            ($signals['wordCount'] ?? 0) >= 500 ? 2 : 0);

        $this->scoreSignal($details, $raw, $max, 'expertise', 'faq_schema',
            'FAQ structuree (Schema.org FAQPage)', $signals['hasFaqSchema'] ?? false, 3);

        // ═══ AUTHORITATIVENESS (6 signals) ═══

        $this->scoreSignal($details, $raw, $max, 'authoritativeness', 'social_profiles',
            'Profils reseaux sociaux lies', in_array('social_profiles', $signals['trustSignals']), 6);

        $this->scoreSignal($details, $raw, $max, 'authoritativeness', 'schema_organization',
            'Schema.org Organization', $signals['schemaOrganization'] !== null, 7);

        $this->scoreSignal($details, $raw, $max, 'authoritativeness', 'org_sameas',
            'Liens sameAs de l\'organisation', !empty($signals['schemaOrganization']['sameAs'] ?? []), 4);

        $this->scoreSignal($details, $raw, $max, 'authoritativeness', 'third_party_reviews',
            'Avis sur plateformes tierces', in_array('third_party_reviews', $signals['trustSignals']), 6);

        $this->scoreSignal($details, $raw, $max, 'authoritativeness', 'editorial_policy',
            'Charte editoriale ou methodologie', in_array('editorial_policy', $signals['trustSignals']), 5);

        $this->scoreSignal($details, $raw, $max, 'authoritativeness', 'breadcrumb',
            'Fil d\'Ariane (BreadcrumbList)', $signals['hasBreadcrumb'] ?? false, 2);

        // ═══ TRUST (8 signals) ═══

        $this->scoreSignal($details, $raw, $max, 'trust', 'https',
            'HTTPS actif', in_array('https', $signals['trustSignals']), 5);

        $this->scoreSignal($details, $raw, $max, 'trust', 'legal_pages',
            'Mentions legales et politique de confidentialite', in_array('legal_pages', $signals['trustSignals']), 6);

        $this->scoreSignal($details, $raw, $max, 'trust', 'contact_info',
            'Coordonnees de contact accessibles', in_array('contact_info', $signals['trustSignals']), 5);

        $this->scoreSignal($details, $raw, $max, 'trust', 'structured_contact',
            'Contact structure (telephone/adresse en Schema.org)', $signals['hasStructuredContact'] ?? false, 4);

        $this->scoreSignal($details, $raw, $max, 'trust', 'certifications',
            'Certifications et labels de confiance', in_array('certifications', $signals['trustSignals']), 4);

        $this->scoreSignal($details, $raw, $max, 'trust', 'cookie_consent',
            'Bandeau de consentement cookies', in_array('cookie_consent', $signals['trustSignals']), 3);

        $this->scoreSignal($details, $raw, $max, 'trust', 'price_transparency',
            'Transparence des prix/tarifs', $signals['hasPriceTransparency'] ?? false, 4);

        $this->scoreSignal($details, $raw, $max, 'trust', 'accessibility',
            'Declaration d\'accessibilite', $signals['hasAccessibility'] ?? false, 3);

        // ═══ NORMALIZATION → /25 each, /100 total ═══
        $breakdown = [];
        foreach (['experience', 'expertise', 'authoritativeness', 'trust'] as $pillar) {
            $breakdown[$pillar] = $max[$pillar] > 0
                ? (int) round(($raw[$pillar] / $max[$pillar]) * 25)
                : 0;
        }

        $total = array_sum($breakdown);

        return [
            'total' => $total,
            'breakdown' => $breakdown,
            'raw' => $raw,
            'max' => $max,
            'signal_details' => $details,
        ];
    }

    /**
     * Score a single signal and track it in details.
     */
    private function scoreSignal(
        array &$details, array &$raw, array &$max,
        string $pillar, string $key, string $label,
        bool $found, int $maxPoints, int $partialPoints = 0
    ): void {
        $points = $found ? $maxPoints : $partialPoints;
        $raw[$pillar] += $points;
        $max[$pillar] += $maxPoints;

        $details[] = [
            'pillar' => $pillar,
            'key' => $key,
            'label' => $label,
            'found' => $found,
            'points' => $points,
            'max_points' => $maxPoints,
            'partial' => $partialPoints > 0 && !$found,
        ];
    }

    // ─── AI Citability Score ─────────────────────────────────────────────

    private function calculateCitabilityScore(array $signals, array $scoring): array
    {
        $breakdown = [];
        $totalPoints = 0;
        $totalMax = 0;

        // 1. E-E-A-T Foundation (30% weight)
        $eeatNormalized = $scoring['total']; // Already /100
        $eeatPoints = (int) round($eeatNormalized * 0.30);
        $breakdown['eeat_foundation'] = ['score' => $eeatPoints, 'max' => 30, 'label' => 'Fondation E-E-A-T'];
        $totalPoints += $eeatPoints;
        $totalMax += 30;

        // 2. Schema.org richness (15%)
        $schemaScore = 0;
        $schemaMax = 15;
        if ($signals['schemaOrganization']) $schemaScore += 4;
        if (count($signals['schemaPersons'] ?? []) > 0) $schemaScore += 4;
        if ($signals['hasFaqSchema'] ?? false) $schemaScore += 2;
        if ($signals['hasHowToSchema'] ?? false) $schemaScore += 2;
        if ($signals['hasBreadcrumb'] ?? false) $schemaScore += 1;
        if ($signals['schemaArticle'] ?? null) $schemaScore += 2;
        $schemaScore = min($schemaScore, $schemaMax);
        $breakdown['schema_richness'] = ['score' => $schemaScore, 'max' => $schemaMax, 'label' => 'Richesse Schema.org'];
        $totalPoints += $schemaScore;
        $totalMax += $schemaMax;

        // 3. Content quality for AI (20%)
        $contentScore = 0;
        $contentMax = 20;
        $wc = $signals['wordCount'] ?? 0;
        if ($wc >= 2000) $contentScore += 6;
        elseif ($wc >= 1000) $contentScore += 4;
        elseif ($wc >= 500) $contentScore += 2;

        $trustedCount = count($signals['trustedOutboundLinks'] ?? []);
        if ($trustedCount >= 3) $contentScore += 5;
        elseif ($trustedCount >= 1) $contentScore += 3;

        // Headings structure
        $headings = $signals['headingsStructure'] ?? [];
        if (isset($headings['h1']) && $headings['h1'] === 1) $contentScore += 2;
        if (isset($headings['h2']) && $headings['h2'] >= 2) $contentScore += 2;

        // Images with alt text
        $imgTotal = $signals['imagesCount'] ?? 0;
        $imgAlt = $signals['imagesWithAlt'] ?? 0;
        if ($imgTotal > 0 && $imgAlt / $imgTotal >= 0.8) $contentScore += 3;
        elseif ($imgTotal > 0 && $imgAlt / $imgTotal >= 0.5) $contentScore += 1;

        // Content freshness
        if (($signals['contentFreshness'] ?? 'unknown') === 'fresh') $contentScore += 2;

        $contentScore = min($contentScore, $contentMax);
        $breakdown['content_quality'] = ['score' => $contentScore, 'max' => $contentMax, 'label' => 'Qualite du contenu pour l\'IA'];
        $totalPoints += $contentScore;
        $totalMax += $contentMax;

        // 4. Source authority (20%)
        $authorityScore = 0;
        $authorityMax = 20;

        if (count($signals['authors'] ?? []) > 0) $authorityScore += 4;
        if (in_array('third_party_reviews', $signals['trustSignals'])) $authorityScore += 4;
        if (in_array('editorial_policy', $signals['trustSignals'])) $authorityScore += 4;
        if ($signals['hasAboutPage'] ?? false) $authorityScore += 3;
        if ($signals['hasAuthorPages'] ?? false) $authorityScore += 3;

        foreach ($signals['schemaPersons'] ?? [] as $person) {
            if (!empty($person['hasCredential']) || !empty($person['alumniOf'])) {
                $authorityScore += 2;
                break;
            }
        }

        $authorityScore = min($authorityScore, $authorityMax);
        $breakdown['source_authority'] = ['score' => $authorityScore, 'max' => $authorityMax, 'label' => 'Autorite de la source'];
        $totalPoints += $authorityScore;
        $totalMax += $authorityMax;

        // 5. AI indexation readiness (15%)
        $aiReadyScore = 0;
        $aiReadyMax = 15;

        if ($signals['hasLlmsTxt'] ?? false) $aiReadyScore += 5;
        if (in_array('canonical', $signals['trustSignals'])) $aiReadyScore += 2;
        if (in_array('hreflang', $signals['trustSignals'])) $aiReadyScore += 2;
        if ($signals['hasFaqSchema'] ?? false) $aiReadyScore += 3;
        if ($signals['hasHowToSchema'] ?? false) $aiReadyScore += 3;

        $aiReadyScore = min($aiReadyScore, $aiReadyMax);
        $breakdown['ai_readiness'] = ['score' => $aiReadyScore, 'max' => $aiReadyMax, 'label' => 'Pret pour l\'indexation IA'];
        $totalPoints += $aiReadyScore;
        $totalMax += $aiReadyMax;

        $finalScore = $totalMax > 0 ? (int) round(($totalPoints / $totalMax) * 100) : 0;

        return [
            'score' => $finalScore,
            'breakdown' => $breakdown,
            'total_points' => $totalPoints,
            'total_max' => $totalMax,
        ];
    }

    // ─── Smart Recommendations ───────────────────────────────────────────

    private function generateSmartRecommendations(array $signals, array $scoring, array $citability): array
    {
        $recs = [];

        // Priority: signals with most impact on citability that are missing
        foreach ($scoring['signal_details'] as $detail) {
            if (!$detail['found'] && !$detail['partial']) {
                $impact = $this->getRecommendationForSignal($detail['key'], $detail['pillar'], $detail['max_points']);
                if ($impact) {
                    $recs[] = $impact;
                }
            }
        }

        // AI-specific recommendations
        if (!($signals['hasLlmsTxt'] ?? false)) {
            $recs[] = [
                'priority' => 'high',
                'category' => 'ai_citability',
                'signal' => 'llms_txt',
                'title' => 'Creer un fichier llms.txt',
                'description' => 'Le fichier llms.txt aide les moteurs IA (ChatGPT, Claude, Gemini) a comprendre et citer votre site. Creez-le a la racine de votre domaine avec une description de votre expertise et vos meilleurs contenus.',
                'impact' => 'high',
            ];
        }

        if (!($signals['hasFaqSchema'] ?? false) && !($signals['hasHowToSchema'] ?? false)) {
            $recs[] = [
                'priority' => 'medium',
                'category' => 'ai_citability',
                'signal' => 'structured_content',
                'title' => 'Ajouter des FAQ ou HowTo en Schema.org',
                'description' => 'Les contenus structures (FAQ, HowTo) sont prioritairement repris par les IA dans leurs reponses. Ajoutez du balisage FAQPage ou HowTo sur vos pages cles.',
                'impact' => 'high',
            ];
        }

        $wc = $signals['wordCount'] ?? 0;
        if ($wc < 500) {
            $recs[] = [
                'priority' => 'high',
                'category' => 'content_quality',
                'signal' => 'thin_content',
                'title' => 'Enrichir le contenu (actuellement ' . $wc . ' mots)',
                'description' => 'Les contenus de moins de 500 mots sont rarement cites par les IA. Visez 1500+ mots avec des sous-sections claires (H2/H3) pour maximiser votre citabilite.',
                'impact' => 'high',
            ];
        }

        // Sort by priority
        usort($recs, function ($a, $b) {
            $priorities = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return ($priorities[$a['priority']] ?? 9) <=> ($priorities[$b['priority']] ?? 9);
        });

        return array_slice($recs, 0, 10);
    }

    private function getRecommendationForSignal(string $key, string $pillar, int $maxPoints): ?array
    {
        $recs = [
            'author_identified' => [
                'title' => 'Identifier clairement les auteurs de vos contenus',
                'description' => 'Ajoutez le nom, la photo et une bio pour chaque auteur. Les IA privilegient les contenus dont l\'auteur est identifie et credible.',
            ],
            'author_credentials' => [
                'title' => 'Ajouter les qualifications des auteurs en Schema.org',
                'description' => 'Utilisez hasCredential et knowsAbout dans votre balisage Person pour declarer les certifications et domaines d\'expertise.',
            ],
            'schema_person' => [
                'title' => 'Implementer le Schema.org Person pour vos auteurs',
                'description' => 'Le balisage Person (nom, titre, photo, reseaux sociaux, organisation) renforce l\'expertise percue par les moteurs IA.',
            ],
            'schema_organization' => [
                'title' => 'Ajouter le Schema.org Organization',
                'description' => 'Declarez votre entreprise avec logo, description, adresse, telephone et liens sameAs vers vos profils officiels.',
            ],
            'trusted_sources' => [
                'title' => 'Citer des sources institutionnelles',
                'description' => 'Ajoutez des liens vers des sources .gouv.fr, .edu, Wikipedia, PubMed ou d\'autres references reconnues pour renforcer votre credibilite.',
            ],
            'legal_pages' => [
                'title' => 'Ajouter mentions legales et politique de confidentialite',
                'description' => 'Les pages legales sont un signal de confiance fondamental. Assurez-vous qu\'elles sont accessibles depuis le footer.',
            ],
            'social_profiles' => [
                'title' => 'Lier vos profils reseaux sociaux',
                'description' => 'Ajoutez des liens vers vos profils LinkedIn, Twitter/X, Facebook dans le footer et en Schema.org (sameAs).',
            ],
            'publication_dates' => [
                'title' => 'Afficher les dates de publication et mise a jour',
                'description' => 'Les dates de publication (balise <time> + Schema.org datePublished/dateModified) montrent que votre contenu est maintenu a jour.',
            ],
            'third_party_reviews' => [
                'title' => 'Integrer des avis sur plateformes tierces',
                'description' => 'Les avis Trustpilot, Google Reviews ou G2 renforcent votre autorite. Ajoutez un widget ou un lien vers vos avis.',
            ],
            'editorial_policy' => [
                'title' => 'Publier une charte editoriale',
                'description' => 'Une page expliquant votre processus editorial, vos sources et votre methodologie renforce fortement votre E-E-A-T.',
            ],
            'about_page' => [
                'title' => 'Creer une page A propos detaillee',
                'description' => 'Presentez votre equipe, votre histoire, vos valeurs et votre expertise. C\'est souvent la premiere page evaluee pour l\'E-E-A-T.',
            ],
            'contact_info' => [
                'title' => 'Rendre les coordonnees de contact accessibles',
                'description' => 'Adresse email, telephone et formulaire de contact doivent etre facilement trouvables. Utilisez aussi le Schema.org ContactPoint.',
            ],
            'structured_contact' => [
                'title' => 'Ajouter les coordonnees en Schema.org',
                'description' => 'Declarez telephone, adresse et email dans votre balisage Organization pour que les IA puissent les verifier.',
            ],
        ];

        if (!isset($recs[$key])) return null;

        $priority = $maxPoints >= 6 ? 'high' : ($maxPoints >= 4 ? 'medium' : 'low');

        return array_merge($recs[$key], [
            'priority' => $priority,
            'category' => $pillar,
            'signal' => $key,
            'impact' => $maxPoints >= 6 ? 'high' : ($maxPoints >= 4 ? 'medium' : 'low'),
        ]);
    }

    // ─── Aggregation (multi-page) ────────────────────────────────────────

    private function aggregateSignals(array $allSignals, string $mainUrl): array
    {
        $aggregated = [
            'url' => $mainUrl,
            'authors' => [],
            'schemaPersons' => [],
            'schemaOrganization' => null,
            'schemaArticle' => null,
            'hasFaqSchema' => false,
            'hasHowToSchema' => false,
            'hasBreadcrumb' => false,
            'trustSignals' => [],
            'trustedOutboundLinks' => [],
            'reviewPlatformLinks' => [],
            'editorialPolicyLinks' => [],
            'contentDates' => [],
            'externalLinksCount' => 0,
            'internalLinksCount' => 0,
            'wordCount' => 0,
            'imagesCount' => 0,
            'imagesWithAlt' => 0,
            'headingsStructure' => [],
            'contentFreshness' => 'unknown',
            'hasAboutPage' => false,
            'hasAuthorPages' => false,
            'hasLlmsTxt' => false,
            'hasStructuredContact' => false,
            'hasPriceTransparency' => false,
            'hasAccessibility' => false,
        ];

        foreach ($allSignals as $signals) {
            // Merge arrays
            $aggregated['authors'] = array_merge($aggregated['authors'], $signals['authors'] ?? []);
            $aggregated['schemaPersons'] = array_merge($aggregated['schemaPersons'], $signals['schemaPersons'] ?? []);
            $aggregated['trustSignals'] = array_merge($aggregated['trustSignals'], $signals['trustSignals'] ?? []);
            $aggregated['trustedOutboundLinks'] = array_merge($aggregated['trustedOutboundLinks'], $signals['trustedOutboundLinks'] ?? []);
            $aggregated['reviewPlatformLinks'] = array_merge($aggregated['reviewPlatformLinks'], $signals['reviewPlatformLinks'] ?? []);
            $aggregated['editorialPolicyLinks'] = array_merge($aggregated['editorialPolicyLinks'], $signals['editorialPolicyLinks'] ?? []);
            $aggregated['contentDates'] = array_merge($aggregated['contentDates'], $signals['contentDates'] ?? []);

            // Sum numerics
            $aggregated['externalLinksCount'] += $signals['externalLinksCount'] ?? 0;
            $aggregated['internalLinksCount'] += $signals['internalLinksCount'] ?? 0;
            $aggregated['wordCount'] += $signals['wordCount'] ?? 0;
            $aggregated['imagesCount'] += $signals['imagesCount'] ?? 0;
            $aggregated['imagesWithAlt'] += $signals['imagesWithAlt'] ?? 0;

            // Booleans (any true → true)
            foreach (['hasFaqSchema', 'hasHowToSchema', 'hasBreadcrumb', 'hasAboutPage', 'hasAuthorPages', 'hasLlmsTxt', 'hasStructuredContact', 'hasPriceTransparency', 'hasAccessibility'] as $flag) {
                if ($signals[$flag] ?? false) $aggregated[$flag] = true;
            }

            // Keep first org
            if (!$aggregated['schemaOrganization'] && ($signals['schemaOrganization'] ?? null)) {
                $aggregated['schemaOrganization'] = $signals['schemaOrganization'];
            }
            // Keep first article
            if (!$aggregated['schemaArticle'] && ($signals['schemaArticle'] ?? null)) {
                $aggregated['schemaArticle'] = $signals['schemaArticle'];
            }
        }

        // Deduplicate
        $uniqueAuthors = [];
        foreach ($aggregated['authors'] as $a) {
            $key = strtolower($a['name']);
            if (!isset($uniqueAuthors[$key])) $uniqueAuthors[$key] = $a;
            elseif ($a['url'] && !$uniqueAuthors[$key]['url']) $uniqueAuthors[$key]['url'] = $a['url'];
        }
        $aggregated['authors'] = array_values($uniqueAuthors);

        $uniquePersons = [];
        foreach ($aggregated['schemaPersons'] as $p) {
            $key = strtolower($p['name'] ?? '');
            if ($key && !isset($uniquePersons[$key])) $uniquePersons[$key] = $p;
        }
        $aggregated['schemaPersons'] = array_values($uniquePersons);

        $aggregated['trustSignals'] = array_values(array_unique($aggregated['trustSignals']));
        $aggregated['trustedOutboundLinks'] = array_values(array_unique($aggregated['trustedOutboundLinks']));
        $aggregated['reviewPlatformLinks'] = array_values(array_unique($aggregated['reviewPlatformLinks']));
        $aggregated['editorialPolicyLinks'] = array_values(array_unique($aggregated['editorialPolicyLinks']));

        // Average word count
        $aggregated['wordCount'] = (int) ($aggregated['wordCount'] / count($allSignals));

        // Content freshness from all dates
        $aggregated['contentFreshness'] = $this->evaluateContentFreshness($aggregated['contentDates']);

        return $aggregated;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function fetchHtml(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 15,
            'max_redirects' => 3,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Failed to fetch URL: HTTP ' . $response->getStatusCode());
        }

        $html = $response->getContent();
        if (strlen($html) > 5_000_000) {
            throw new \RuntimeException('Page too large (>5MB)');
        }

        return $html;
    }

    private function evaluateContentFreshness(array $dates): string
    {
        if (empty($dates)) return 'unknown';

        $now = new \DateTimeImmutable();
        $mostRecent = null;

        foreach ($dates as $dateStr) {
            try {
                $date = new \DateTimeImmutable($dateStr);
                if (!$mostRecent || $date > $mostRecent) $mostRecent = $date;
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$mostRecent) return 'unknown';

        $diff = $now->diff($mostRecent);
        $months = ($diff->y * 12) + $diff->m;

        if ($months <= 6) return 'fresh';
        if ($months <= 12) return 'recent';
        return 'stale';
    }
}
