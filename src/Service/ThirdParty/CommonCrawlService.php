<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CommonCrawlService
{
    private const INDEX_URL = 'https://index.commoncrawl.org';
    private const COLLECTIONS_URL = 'https://index.commoncrawl.org/collinfo.json';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function getCollections(): ?array
    {
        $response = $this->httpClient->request('GET', self::COLLECTIONS_URL);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return $response->toArray();
    }

    public function search(string $url, ?string $collection = null): ?array
    {
        if (!$collection) {
            $collections = $this->getCollections();
            $collection = $collections[0]['id'] ?? 'CC-MAIN-2024-10';
        }

        $response = $this->httpClient->request('GET', self::INDEX_URL . '/' . $collection . '-index', [
            'query' => [
                'url' => $url,
                'output' => 'json',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $content = $response->getContent();
        $lines = explode("\n", trim($content));
        $results = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $data = json_decode($line, true);
            if ($data) {
                $results[] = $data;
            }
        }

        return [
            'url' => $url,
            'collection' => $collection,
            'resultCount' => count($results),
            'results' => $results,
        ];
    }

    public function getUrlHistory(string $domain): ?array
    {
        $collections = $this->getCollections();

        if (!$collections) {
            return null;
        }

        $history = [];

        foreach (array_slice($collections, 0, 5) as $collection) {
            $result = $this->search($domain . '/*', $collection['id']);
            if ($result && $result['resultCount'] > 0) {
                $history[] = [
                    'collection' => $collection['id'],
                    'name' => $collection['name'] ?? $collection['id'],
                    'urlCount' => $result['resultCount'],
                ];
            }
        }

        return [
            'domain' => $domain,
            'history' => $history,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
