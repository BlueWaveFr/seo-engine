<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleCustomSearchService
{
    private const API_URL = 'https://www.googleapis.com/customsearch/v1';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager
    ) {}

    public function search(string $query, int $start = 1, int $num = 10): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_CUSTOM_SEARCH);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $config = $credentials['additionalConfig'] ?? [];
        $searchEngineId = $config['searchEngineId'] ?? $config['cx'] ?? null;

        if (!$searchEngineId) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'key' => $credentials['apiKey'],
                'cx' => $searchEngineId,
                'q' => $query,
                'start' => $start,
                'num' => min($num, 10),
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return [
            'totalResults' => (int) ($data['searchInformation']['totalResults'] ?? 0),
            'searchTime' => (float) ($data['searchInformation']['searchTime'] ?? 0),
            'items' => array_map(fn($item) => [
                'title' => $item['title'] ?? '',
                'link' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'displayLink' => $item['displayLink'] ?? '',
            ], $data['items'] ?? []),
        ];
    }

    public function isConfigured(): bool
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_CUSTOM_SEARCH);

        if (!$credentials || !$credentials['apiKey']) {
            return false;
        }

        $config = $credentials['additionalConfig'] ?? [];
        return isset($config['searchEngineId']) || isset($config['cx']);
    }
}
