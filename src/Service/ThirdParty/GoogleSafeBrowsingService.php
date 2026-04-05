<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleSafeBrowsingService
{
    private const API_URL = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager
    ) {}

    public function checkUrl(string $url): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_SAFE_BROWSING);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $response = $this->httpClient->request('POST', self::API_URL, [
            'query' => [
                'key' => $credentials['apiKey'],
            ],
            'json' => [
                'client' => [
                    'clientId' => 'waverank',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => [
                        ['url' => $url],
                    ],
                ],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return [
            'isSafe' => empty($data['matches']),
            'threats' => $data['matches'] ?? [],
        ];
    }

    public function checkUrls(array $urls): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_SAFE_BROWSING);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $threatEntries = array_map(fn($url) => ['url' => $url], $urls);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'query' => [
                'key' => $credentials['apiKey'],
            ],
            'json' => [
                'client' => [
                    'clientId' => 'waverank',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => $threatEntries,
                ],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();
        $threats = $data['matches'] ?? [];

        $results = [];
        foreach ($urls as $url) {
            $urlThreats = array_filter($threats, fn($t) => $t['threat']['url'] === $url);
            $results[$url] = [
                'isSafe' => empty($urlThreats),
                'threats' => array_values($urlThreats),
            ];
        }

        return $results;
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyManager->isConfigured(ApiKey::PROVIDER_GOOGLE_SAFE_BROWSING);
    }
}
