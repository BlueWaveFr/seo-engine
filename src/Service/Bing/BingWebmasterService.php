<?php

namespace SeoExpert\Engine\Service\Bing;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bing Webmaster Tools API Service
 *
 * Documentation: https://learn.microsoft.com/en-us/bingwebmaster/
 */
class BingWebmasterService
{
    private const API_BASE_URL = 'https://ssl.bing.com/webmaster/api.svc/json';

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
            $this->logger->error('BingWebmasterService init error: ' . $e->getMessage());
        }
    }

    private function initializeClient(): void
    {
        // Try to get API key from database first
        $apiKeyRepo = $this->entityManager->getRepository(ApiKey::class);
        $bingKey = $apiKeyRepo->findOneBy(['provider' => 'bing_webmaster', 'isActive' => true]);

        if ($bingKey) {
            $this->apiKey = $bingKey->getApiKey();
            $this->isConfigured = true;
            $this->logger->info('Bing Webmaster API configured from database');
            return;
        }

        // Fallback to environment variable
        $envKey = $_ENV['BING_WEBMASTER_API_KEY'] ?? null;
        if ($envKey) {
            $this->apiKey = $envKey;
            $this->isConfigured = true;
            $this->logger->info('Bing Webmaster API configured from environment');
            return;
        }

        $this->logger->info('Bing Webmaster API not configured');
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
     * Submit a URL for indexing
     */
    public function submitUrl(string $siteUrl, string $url): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Webmaster API not configured. Add API key in Admin > API Keys > Bing Webmaster.',
            ];
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/SubmitUrl', [
                'query' => [
                    'apikey' => $this->apiKey,
                    'siteUrl' => $siteUrl,
                    'url' => $url,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $this->logger->info("URL submitted to Bing: {$url}");
                return [
                    'success' => true,
                    'url' => $url,
                    'message' => 'URL submitted successfully',
                ];
            }

            $content = $response->getContent(false);
            return [
                'success' => false,
                'url' => $url,
                'error' => $content ?: "HTTP {$statusCode}",
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to submit URL to Bing: " . $e->getMessage());
            return [
                'success' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Submit multiple URLs for indexing (batch)
     */
    public function submitUrls(string $siteUrl, array $urls): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Webmaster API not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/SubmitUrlBatch', [
                'query' => [
                    'apikey' => $this->apiKey,
                    'siteUrl' => $siteUrl,
                ],
                'json' => [
                    'siteUrl' => $siteUrl,
                    'urlList' => $urls,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $this->logger->info("Batch URLs submitted to Bing: " . count($urls) . " URLs");
                return [
                    'success' => true,
                    'count' => count($urls),
                    'message' => count($urls) . ' URLs submitted successfully',
                ];
            }

            $content = $response->getContent(false);
            return [
                'success' => false,
                'error' => $content ?: "HTTP {$statusCode}",
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to submit batch URLs to Bing: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get URL submission quota
     */
    public function getUrlSubmissionQuota(string $siteUrl): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Webmaster API not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/GetUrlSubmissionQuota', [
                'query' => [
                    'apikey' => $this->apiKey,
                    'siteUrl' => $siteUrl,
                ],
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'dailyQuota' => $data['DailyQuota'] ?? null,
                'monthlyQuota' => $data['MonthlyQuota'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get Bing quota: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of sites registered in Bing Webmaster Tools
     */
    public function getSites(): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Webmaster API not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/GetUserSites', [
                'query' => [
                    'apikey' => $this->apiKey,
                ],
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'sites' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get Bing sites: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get crawl stats for a site
     */
    public function getCrawlStats(string $siteUrl): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Webmaster API not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/GetCrawlStats', [
                'query' => [
                    'apikey' => $this->apiKey,
                    'siteUrl' => $siteUrl,
                ],
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'stats' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get Bing crawl stats: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get query stats (search analytics)
     */
    public function getQueryStats(string $siteUrl): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Bing Webmaster API not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/GetQueryStats', [
                'query' => [
                    'apikey' => $this->apiKey,
                    'siteUrl' => $siteUrl,
                ],
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'queries' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get Bing query stats: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
