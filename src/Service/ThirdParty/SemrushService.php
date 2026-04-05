<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SemrushService
{
    private const API_URL = 'https://api.semrush.com/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager
    ) {}

    public function getDomainOverview(string $domain, string $database = 'fr'): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_SEMRUSH);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'type' => 'domain_ranks',
                'key' => $credentials['apiKey'],
                'export_columns' => 'Dn,Rk,Or,Ot,Oc,Ad,At,Ac',
                'domain' => $domain,
                'database' => $database,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $content = $response->getContent();
        $lines = explode("\n", trim($content));

        if (count($lines) < 2) {
            return null;
        }

        $headers = str_getcsv($lines[0], ';');
        $values = str_getcsv($lines[1], ';');

        $result = [];
        foreach ($headers as $i => $header) {
            $result[$header] = $values[$i] ?? null;
        }

        return [
            'domain' => $domain,
            'rank' => (int) ($result['Rk'] ?? 0),
            'organicKeywords' => (int) ($result['Or'] ?? 0),
            'organicTraffic' => (int) ($result['Ot'] ?? 0),
            'organicCost' => (float) ($result['Oc'] ?? 0),
            'adwordsKeywords' => (int) ($result['Ad'] ?? 0),
            'adwordsTraffic' => (int) ($result['At'] ?? 0),
            'adwordsCost' => (float) ($result['Ac'] ?? 0),
            'rawData' => $result,
        ];
    }

    public function getOrganicKeywords(string $domain, string $database = 'fr', int $limit = 100): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_SEMRUSH);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'type' => 'domain_organic',
                'key' => $credentials['apiKey'],
                'export_columns' => 'Ph,Po,Pp,Pd,Nq,Cp,Ur,Tr,Tc,Co,Nr',
                'domain' => $domain,
                'database' => $database,
                'display_limit' => $limit,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $content = $response->getContent();
        $lines = explode("\n", trim($content));

        if (count($lines) < 2) {
            return [
                'domain' => $domain,
                'keywords' => [],
            ];
        }

        $headers = str_getcsv(array_shift($lines), ';');
        $keywords = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $values = str_getcsv($line, ';');
            $keyword = [];
            foreach ($headers as $i => $header) {
                $keyword[$header] = $values[$i] ?? null;
            }
            $keywords[] = $keyword;
        }

        return [
            'domain' => $domain,
            'keywordCount' => count($keywords),
            'keywords' => $keywords,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyManager->isConfigured(ApiKey::PROVIDER_SEMRUSH);
    }
}
