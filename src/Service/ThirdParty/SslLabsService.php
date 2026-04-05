<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SslLabsService
{
    private const API_URL = 'https://api.ssllabs.com/api/v3';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function analyze(string $host, bool $publish = false, bool $startNew = false): ?array
    {
        $response = $this->httpClient->request('GET', self::API_URL . '/analyze', [
            'query' => [
                'host' => $host,
                'publish' => $publish ? 'on' : 'off',
                'startNew' => $startNew ? 'on' : 'off',
                'all' => 'done',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return [
            'host' => $data['host'] ?? $host,
            'status' => $data['status'] ?? 'UNKNOWN',
            'grade' => $data['endpoints'][0]['grade'] ?? null,
            'gradeTrustIgnored' => $data['endpoints'][0]['gradeTrustIgnored'] ?? null,
            'hasWarnings' => $data['endpoints'][0]['hasWarnings'] ?? false,
            'isExceptional' => $data['endpoints'][0]['isExceptional'] ?? false,
            'progress' => $data['endpoints'][0]['progress'] ?? 0,
            'statusMessage' => $data['endpoints'][0]['statusMessage'] ?? '',
            'endpoints' => $data['endpoints'] ?? [],
            'rawData' => $data,
        ];
    }

    public function getInfo(): ?array
    {
        $response = $this->httpClient->request('GET', self::API_URL . '/info');

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return $response->toArray();
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
