<?php

namespace SeoExpert\Engine\Service\Google;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SafeBrowsingService
{
    private const API_URL = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    // Threat types to check
    private const THREAT_TYPES = [
        'MALWARE',
        'SOCIAL_ENGINEERING',
        'UNWANTED_SOFTWARE',
        'POTENTIALLY_HARMFUL_APPLICATION',
    ];

    // Platform types to check
    private const PLATFORM_TYPES = [
        'ANY_PLATFORM',
        'WINDOWS',
        'LINUX',
        'OSX',
        'ANDROID',
        'IOS',
    ];

    private string $effectiveApiKey;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
        $this->effectiveApiKey = $this->apiKey;
        if ($this->entityManager) {
            try {
                $dbKey = $this->entityManager->getRepository(ApiKey::class)
                    ->findOneBy(['provider' => ApiKey::PROVIDER_GOOGLE_SAFE_BROWSING, 'isActive' => true]);
                if ($dbKey && $dbKey->getApiKey()) {
                    $this->effectiveApiKey = $dbKey->getApiKey();
                }
            } catch (\Exception $e) {}
        }
    }

    /**
     * Check if a URL is safe according to Google Safe Browsing
     */
    public function checkUrl(string $url): array
    {
        return $this->checkUrls([$url])[$url] ?? ['safe' => false, 'error' => 'Unknown error'];
    }

    /**
     * Check multiple URLs for threats
     * @param array $urls List of URLs to check
     * @return array Keyed by URL with safety status
     */
    public function checkUrls(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        // Limit to 500 URLs per request (API limit)
        $urls = array_slice($urls, 0, 500);

        try {
            $threatEntries = array_map(fn($url) => ['url' => $url], $urls);

            $requestBody = [
                'client' => [
                    'clientId' => 'seo-audit-app',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => self::THREAT_TYPES,
                    'platformTypes' => self::PLATFORM_TYPES,
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => $threatEntries,
                ],
            ];

            $response = $this->httpClient->request('POST', self::API_URL, [
                'query' => ['key' => $this->effectiveApiKey],
                'json' => $requestBody,
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            // Initialize all URLs as safe
            $results = [];
            foreach ($urls as $url) {
                $results[$url] = [
                    'safe' => true,
                    'threats' => [],
                ];
            }

            // Mark URLs with threats
            if (!empty($data['matches'])) {
                foreach ($data['matches'] as $match) {
                    $threatUrl = $match['threat']['url'] ?? null;
                    if ($threatUrl && isset($results[$threatUrl])) {
                        $results[$threatUrl]['safe'] = false;
                        $threatType = $match['threatType'] ?? 'UNKNOWN';
                        $results[$threatUrl]['threats'][] = [
                            'type' => $threatType,
                            'description' => self::getThreatDescription($threatType),
                            'platform' => $match['platformType'] ?? 'ANY_PLATFORM',
                            'cache_duration' => $match['cacheDuration'] ?? null,
                        ];
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Safe Browsing API error: ' . $e->getMessage(), [
                'urls_count' => count($urls),
            ]);

            // Return error state for all URLs
            $results = [];
            foreach ($urls as $url) {
                $results[$url] = [
                    'safe' => null, // Unknown
                    'error' => $e->getMessage(),
                ];
            }
            return $results;
        }
    }

    /**
     * Get a detailed safety report for a domain
     */
    public function getDomainReport(string $domain): array
    {
        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        // Check various URL patterns
        $urlsToCheck = [
            "https://{$domain}",
            "https://{$domain}/",
            "http://{$domain}",
            "http://{$domain}/",
            "https://www.{$domain}",
            "http://www.{$domain}",
        ];

        $results = $this->checkUrls($urlsToCheck);

        // Aggregate results
        $threats = [];
        $isSafe = true;
        $error = null;

        foreach ($results as $url => $result) {
            if ($result['safe'] === false) {
                $isSafe = false;
                foreach ($result['threats'] as $threat) {
                    $threats[] = array_merge($threat, ['url' => $url]);
                }
            } elseif ($result['safe'] === null) {
                // Unknown state (API error)
                $isSafe = null;
                if (isset($result['error']) && !$error) {
                    $error = $result['error'];
                }
            }
        }

        $report = [
            'domain' => $domain,
            'safe' => $isSafe,
            'threats' => $threats,
            'threat_count' => count($threats),
            'checked_urls' => array_keys($results),
            'checked_at' => (new \DateTimeImmutable())->format('c'),
        ];

        if ($error) {
            $report['error'] = $error;
        }

        return $report;
    }

    /**
     * Get threat type description
     */
    public static function getThreatDescription(string $threatType): string
    {
        return match ($threatType) {
            'MALWARE' => 'Ce site peut installer des logiciels malveillants sur votre ordinateur',
            'SOCIAL_ENGINEERING' => 'Ce site peut tenter de voler vos informations personnelles (phishing)',
            'UNWANTED_SOFTWARE' => 'Ce site peut contenir des logiciels indésirables',
            'POTENTIALLY_HARMFUL_APPLICATION' => 'Ce site peut proposer des applications potentiellement dangereuses',
            default => 'Menace de sécurité détectée',
        };
    }
}
