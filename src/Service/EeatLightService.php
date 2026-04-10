<?php

namespace SeoExpert\Engine\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EeatLightService
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; Optimize360Bot/1.0; +https://optimize360.io)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    public function analyze(string $url): array
    {
        $html = $this->fetchHtml($url);

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($doc);

        $eeat = $this->extractEeatSignals($xpath, $html, $url);
        $this->calculateEeatScore($eeat);

        return [
            'score' => $eeat['score'],
            'breakdown' => $eeat['breakdown'],
            'trustSignals' => $eeat['trustSignals'],
            'authors' => $eeat['authors'],
            'schemaOrganization' => $eeat['schemaOrganization'],
            'schemaPersons' => $eeat['schemaPersons'],
            'contentFreshness' => $eeat['contentFreshness'] ?? 'unknown',
            'recommendations' => $eeat['recommendations'],
            'url' => $url,
            'analyzedAt' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    private function fetchHtml(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT],
            'timeout' => 10,
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

    private function extractEeatSignals(\DOMXPath $xpath, string $html, string $url): array
    {
        $eeat = [
            'authors' => [],
            'schemaPersons' => [],
            'schemaOrganization' => null,
            'schemaArticle' => null,
            'trustSignals' => [],
            'trustedOutboundLinks' => [],
            'reviewPlatformLinks' => [],
            'editorialPolicyLinks' => [],
            'contentDates' => [],
            'externalLinksCount' => 0,
        ];

        // 1. Extract Schema.org structured data
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

        // 2. Extract author info from common HTML patterns
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

        // Deduplicate authors
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

        // 3. Extract trust signals
        $socialLinks = $xpath->query('//a[contains(@href, "linkedin.com") or contains(@href, "twitter.com") or contains(@href, "facebook.com")]');
        if ($socialLinks->length > 0) {
            $eeat['trustSignals'][] = 'social_profiles';
        }

        $legalLinks = $xpath->query('//a[contains(@href, "privacy") or contains(@href, "politique") or contains(@href, "mentions-legales") or contains(@href, "terms") or contains(@href, "conditions")]');
        if ($legalLinks->length > 0) {
            $eeat['trustSignals'][] = 'legal_pages';
        }

        $contactPatterns = ['//a[contains(@href, "contact")]', '//a[contains(@href, "mailto:")]', '//a[contains(@href, "tel:")]'];
        foreach ($contactPatterns as $pattern) {
            if ($xpath->query($pattern)->length > 0) {
                $eeat['trustSignals'][] = 'contact_info';
                break;
            }
        }

        if (str_starts_with($url, 'https://')) {
            $eeat['trustSignals'][] = 'https';
        }

        $reviewPatterns = $xpath->query('//*[contains(@class, "review") or contains(@class, "testimonial") or contains(@class, "avis")]');
        if ($reviewPatterns->length > 0) {
            $eeat['trustSignals'][] = 'reviews';
        }

        $certPatterns = $xpath->query('//*[contains(@class, "certif") or contains(@class, "badge") or contains(@class, "trust") or contains(@alt, "certif")]');
        if ($certPatterns->length > 0) {
            $eeat['trustSignals'][] = 'certifications';
        }

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

        // 4. Trusted outbound links
        $trustedDomains = [
            '.gouv.fr', '.gov', '.edu', '.ac.uk', '.ac.fr',
            'wikipedia.org', 'who.int', 'insee.fr', 'legifrance.gouv.fr',
            'europa.eu', 'nih.gov', 'cdc.gov', 'pubmed.ncbi.nlm.nih.gov',
            'scholar.google.com', 'nature.com', 'science.org',
            'banque-france.fr', 'ecb.europa.eu', 'amf-france.org',
        ];

        $currentDomain = parse_url($url, PHP_URL_HOST);
        $allLinks = $xpath->query('//a[@href]');
        foreach ($allLinks as $link) {
            $href = $link->getAttribute('href');
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) continue;

            $linkHost = parse_url($href, PHP_URL_HOST);
            if (!$linkHost || $linkHost === $currentDomain || str_ends_with($linkHost, '.' . $currentDomain)) continue;

            $eeat['externalLinksCount']++;

            foreach ($trustedDomains as $trusted) {
                if (str_ends_with($linkHost, $trusted) || $linkHost === ltrim($trusted, '.')) {
                    $eeat['trustedOutboundLinks'][] = $href;
                    break;
                }
            }
        }

        // 5. Review platform links
        $reviewPlatforms = [
            'trustpilot.com', 'google.com/maps', 'g2.com', 'capterra.com',
            'avis-verifies.com', 'tripadvisor.com', 'yelp.com', 'glassdoor.com',
        ];
        foreach ($allLinks as $link) {
            $href = $link->getAttribute('href');
            foreach ($reviewPlatforms as $platform) {
                if (str_contains($href, $platform)) {
                    $eeat['reviewPlatformLinks'][] = $href;
                    $eeat['trustSignals'][] = 'third_party_reviews';
                    break;
                }
            }
        }
        $reviewWidgetPatterns = $xpath->query('//*[contains(@class, "trustpilot") or contains(@id, "trustpilot") or contains(@class, "google-reviews") or contains(@class, "avis-verifies")]');
        if ($reviewWidgetPatterns->length > 0) {
            $eeat['trustSignals'][] = 'third_party_reviews';
        }

        // 6. Editorial policy
        $editorialPatterns = $xpath->query('//a[contains(@href, "charte-editoriale") or contains(@href, "editorial-policy") or contains(@href, "correction-policy") or contains(@href, "fact-checking") or contains(@href, "methodologie") or contains(@href, "methodology")]');
        foreach ($editorialPatterns as $link) {
            $eeat['editorialPolicyLinks'][] = $link->getAttribute('href');
        }
        if (count($eeat['editorialPolicyLinks']) > 0) {
            $eeat['trustSignals'][] = 'editorial_policy';
        }

        // 7. Content dates
        $timeElements = $xpath->query('//time[@datetime]');
        foreach ($timeElements as $time) {
            $datetime = $time->getAttribute('datetime');
            if ($datetime) $eeat['contentDates'][] = $datetime;
        }
        $dateMetas = $xpath->query('//meta[@property="article:published_time" or @property="article:modified_time"]/@content');
        foreach ($dateMetas as $meta) {
            if ($meta->nodeValue) $eeat['contentDates'][] = $meta->nodeValue;
        }
        if (!empty($eeat['schemaArticle']['dateModified'])) {
            $eeat['contentDates'][] = $eeat['schemaArticle']['dateModified'];
        } elseif (!empty($eeat['schemaArticle']['datePublished'])) {
            $eeat['contentDates'][] = $eeat['schemaArticle']['datePublished'];
        }

        $eeat['reviewPlatformLinks'] = array_unique($eeat['reviewPlatformLinks']);
        $eeat['editorialPolicyLinks'] = array_unique($eeat['editorialPolicyLinks']);
        $eeat['trustedOutboundLinks'] = array_values(array_unique($eeat['trustedOutboundLinks']));
        $eeat['trustSignals'] = array_values(array_unique($eeat['trustSignals']));

        return $eeat;
    }

    private function parseSchemaData($data, array &$eeat): void
    {
        if (!is_array($data)) return;

        if (isset($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                $this->parseSchemaData($item, $eeat);
            }
            return;
        }

        $type = $data['@type'] ?? null;
        if (!$type) return;

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

                    if (isset($data['author'])) {
                        $authorData = $data['author'];
                        if (is_array($authorData)) {
                            if (isset($authorData['@type'])) {
                                $article['author'] = [
                                    'name' => $authorData['name'] ?? null,
                                    'url' => $authorData['url'] ?? null,
                                ];
                            } elseif (isset($authorData[0])) {
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

    private function evaluateContentFreshness(array $dates): string
    {
        if (empty($dates)) return 'unknown';

        $now = new \DateTimeImmutable();
        $mostRecent = null;

        foreach ($dates as $dateStr) {
            try {
                $date = new \DateTimeImmutable($dateStr);
                if (!$mostRecent || $date > $mostRecent) {
                    $mostRecent = $date;
                }
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

    private function calculateEeatScore(array &$eeat): void
    {
        // Light version: skip multi-page signals (authorPages, aboutPages, brandedSearchVolume, domainAge, Wikipedia)
        $rawBreakdown = [
            'experience' => 0,
            'expertise' => 0,
            'authoritativeness' => 0,
            'trust' => 0,
        ];
        // Adjusted max: removed authorPages(10), multipleAuthors(5) from experience=13
        // Removed aboutPages(5) from expertise=25, kept trusted links
        // Removed branded(5), domainAge(2), wikipedia(3) from authoritativeness=20
        // Trust stays at 35
        $rawMax = [
            'experience' => 13,
            'expertise' => 25,
            'authoritativeness' => 20,
            'trust' => 35,
        ];
        $recommendations = [];

        // === EXPERIENCE (13 raw pts max) ===

        // Publication dates (5 pts)
        if (in_array('publication_date', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['experience'] += 5;
        } else {
            $recommendations[] = 'Ajoutez des dates de publication et de mise a jour sur vos articles';
        }

        // Has at least one author (5 pts)
        if (count($eeat['authors'] ?? []) > 0) {
            $rawBreakdown['experience'] += 5;
        } else {
            $recommendations[] = 'Identifiez clairement les auteurs de vos contenus';
        }

        // Content freshness (3 pts)
        $contentFreshness = $this->evaluateContentFreshness($eeat['contentDates'] ?? []);
        $eeat['contentFreshness'] = $contentFreshness;
        if ($contentFreshness === 'fresh') {
            $rawBreakdown['experience'] += 3;
        } elseif ($contentFreshness === 'recent') {
            $rawBreakdown['experience'] += 2;
        } elseif (!empty($eeat['contentDates'])) {
            $recommendations[] = 'Mettez a jour votre contenu regulierement — la derniere modification date de plus de 12 mois';
        }

        // === EXPERTISE (25 raw pts max) ===

        $hasCredentials = false;
        $hasJobTitle = false;
        foreach ($eeat['schemaPersons'] ?? [] as $person) {
            if (!empty($person['hasCredential']) || !empty($person['knowsAbout'])) {
                $hasCredentials = true;
            }
            if (!empty($person['jobTitle'])) {
                $hasJobTitle = true;
            }
        }

        // Credentials (10 pts)
        if ($hasCredentials) {
            $rawBreakdown['expertise'] += 10;
        } else {
            $recommendations[] = 'Ajoutez les qualifications et certifications de vos auteurs dans le Schema.org Person';
        }

        // Job title (5 pts)
        if ($hasJobTitle) {
            $rawBreakdown['expertise'] += 5;
        }

        // Schema.org Person (5 pts)
        if (count($eeat['schemaPersons'] ?? []) > 0) {
            $rawBreakdown['expertise'] += 5;
        } else {
            $recommendations[] = 'Implementez le Schema.org Person pour vos auteurs';
        }

        // Trusted outbound links (5 pts)
        $trustedLinksCount = count($eeat['trustedOutboundLinks'] ?? []);
        if ($trustedLinksCount >= 2) {
            $rawBreakdown['expertise'] += 5;
        } elseif ($trustedLinksCount === 1) {
            $rawBreakdown['expertise'] += 3;
        } else {
            $recommendations[] = 'Ajoutez des liens vers des sources institutionnelles (.gouv.fr, .edu, Wikipedia) pour renforcer votre credibilite';
        }

        // === AUTHORITATIVENESS (20 raw pts max) ===

        // Social profiles (8 pts)
        if (in_array('social_profiles', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['authoritativeness'] += 8;
        } else {
            $recommendations[] = 'Liez vos profils LinkedIn, Twitter et autres reseaux sociaux';
        }

        // Organization schema (7 pts)
        if ($eeat['schemaOrganization'] ?? null) {
            $rawBreakdown['authoritativeness'] += 7;
            if (!empty($eeat['schemaOrganization']['sameAs'])) {
                $rawBreakdown['authoritativeness'] += 5;
            }
        } else {
            $recommendations[] = 'Ajoutez le Schema.org Organization pour votre entreprise';
        }

        // === TRUST (35 raw pts max) ===

        if (in_array('https', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 5;
        }

        if (in_array('legal_pages', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 5;
        } else {
            $recommendations[] = 'Ajoutez des liens vers vos mentions legales et politique de confidentialite';
        }

        if (in_array('contact_info', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 5;
        } else {
            $recommendations[] = 'Rendez vos coordonnees de contact facilement accessibles';
        }

        if (in_array('reviews', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 5;
        }

        if (in_array('certifications', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 5;
        }

        if (in_array('third_party_reviews', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 5;
        } else {
            $recommendations[] = 'Integrez des avis tiers (Trustpilot, Google Reviews, G2) pour renforcer la confiance';
        }

        if ($contentFreshness === 'fresh') {
            $rawBreakdown['trust'] += 2;
        } elseif ($contentFreshness === 'recent') {
            $rawBreakdown['trust'] += 1;
        }

        if (in_array('editorial_policy', $eeat['trustSignals'] ?? [])) {
            $rawBreakdown['trust'] += 3;
        }

        // === NORMALIZATION: each category -> /25, total -> /100 ===
        $breakdown = [
            'experience' => (int) round(($rawBreakdown['experience'] / $rawMax['experience']) * 25),
            'expertise' => (int) round(($rawBreakdown['expertise'] / $rawMax['expertise']) * 25),
            'authoritativeness' => (int) round(($rawBreakdown['authoritativeness'] / $rawMax['authoritativeness']) * 25),
            'trust' => (int) round(($rawBreakdown['trust'] / $rawMax['trust']) * 25),
        ];

        $eeat['score'] = $breakdown['experience'] + $breakdown['expertise'] + $breakdown['authoritativeness'] + $breakdown['trust'];
        $eeat['breakdown'] = $breakdown;
        $eeat['recommendations'] = array_slice($recommendations, 0, 7);
    }
}
