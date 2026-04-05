<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class WaybackMachineService
{
    private const API_URL = 'https://archive.org/wayback/available';
    private const CDX_API_URL = 'https://web.archive.org/cdx/search/cdx';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function getLatestSnapshot(string $url): ?array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'url' => $url,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        $snapshot = $data['archived_snapshots']['closest'] ?? null;

        if (!$snapshot) {
            return [
                'available' => false,
                'url' => $url,
            ];
        }

        return [
            'available' => true,
            'url' => $snapshot['url'] ?? null,
            'timestamp' => $snapshot['timestamp'] ?? null,
            'status' => $snapshot['status'] ?? null,
        ];
    }

    public function getSnapshots(string $url, ?string $from = null, ?string $to = null, int $limit = 100): ?array
    {
        $query = [
            'url' => $url,
            'output' => 'json',
            'limit' => $limit,
        ];

        if ($from) {
            $query['from'] = $from;
        }
        if ($to) {
            $query['to'] = $to;
        }

        $response = $this->httpClient->request('GET', self::CDX_API_URL, [
            'query' => $query,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        if (empty($data) || count($data) < 2) {
            return [
                'url' => $url,
                'snapshots' => [],
            ];
        }

        $headers = array_shift($data);
        $snapshots = [];

        foreach ($data as $row) {
            $snapshot = [];
            foreach ($headers as $i => $header) {
                $snapshot[$header] = $row[$i] ?? null;
            }
            $snapshots[] = $snapshot;
        }

        return [
            'url' => $url,
            'snapshotCount' => count($snapshots),
            'snapshots' => $snapshots,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
