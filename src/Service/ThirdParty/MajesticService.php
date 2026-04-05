<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MajesticService
{
    private const API_URL = 'https://api.majestic.com/api/json';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager
    ) {}

    public function getBacklinkData(string $url): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_MAJESTIC);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'app_api_key' => $credentials['apiKey'],
                'cmd' => 'GetIndexItemInfo',
                'items' => 1,
                'item0' => $url,
                'datasource' => 'fresh',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        if (($data['Code'] ?? '') !== 'OK') {
            return null;
        }

        $result = $data['DataTables']['Results']['Data'][0] ?? null;

        if (!$result) {
            return null;
        }

        return [
            'url' => $url,
            'trustFlow' => $result['TrustFlow'] ?? null,
            'citationFlow' => $result['CitationFlow'] ?? null,
            'refDomains' => $result['RefDomains'] ?? null,
            'externalBacklinks' => $result['ExtBackLinks'] ?? null,
            'refIPs' => $result['RefIPs'] ?? null,
            'refSubnets' => $result['RefSubNets'] ?? null,
            'rawData' => $result,
        ];
    }

    public function getTopBacklinks(string $url, int $count = 50): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_MAJESTIC);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'app_api_key' => $credentials['apiKey'],
                'cmd' => 'GetBackLinkData',
                'item' => $url,
                'Count' => $count,
                'datasource' => 'fresh',
                'Mode' => 0,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        if (($data['Code'] ?? '') !== 'OK') {
            return null;
        }

        return [
            'url' => $url,
            'backlinks' => $data['DataTables']['BackLinks']['Data'] ?? [],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyManager->isConfigured(ApiKey::PROVIDER_MAJESTIC);
    }
}
