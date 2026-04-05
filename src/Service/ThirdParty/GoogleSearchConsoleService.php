<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleSearchConsoleService
{
    private const API_URL = 'https://www.googleapis.com/webmasters/v3';
    private const SEARCHANALYTICS_URL = 'https://searchconsole.googleapis.com/v1';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager
    ) {}

    public function getSites(): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_SEARCH_CONSOLE);

        if (!$credentials || !$credentials['accessToken']) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL . '/sites', [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['accessToken'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return $data['siteEntry'] ?? [];
    }

    public function getSearchAnalytics(
        string $siteUrl,
        string $startDate,
        string $endDate,
        array $dimensions = ['query'],
        int $rowLimit = 1000
    ): ?array {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_SEARCH_CONSOLE);

        if (!$credentials || !$credentials['accessToken']) {
            return null;
        }

        $encodedSiteUrl = urlencode($siteUrl);

        $response = $this->httpClient->request('POST', self::SEARCHANALYTICS_URL . "/sites/{$encodedSiteUrl}/searchAnalytics/query", [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['accessToken'],
            ],
            'json' => [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => $dimensions,
                'rowLimit' => $rowLimit,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return [
            'siteUrl' => $siteUrl,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rowCount' => count($data['rows'] ?? []),
            'rows' => $data['rows'] ?? [],
        ];
    }

    public function getTopQueries(string $siteUrl, int $days = 28, int $limit = 100): ?array
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        return $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['query'], $limit);
    }

    public function getTopPages(string $siteUrl, int $days = 28, int $limit = 100): ?array
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        return $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['page'], $limit);
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyManager->isConfigured(ApiKey::PROVIDER_GOOGLE_SEARCH_CONSOLE);
    }
}
