<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GooglePageSpeedService
{
    private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ApiKeyManager $apiKeyManager
    ) {}

    public function analyze(string $url, string $strategy = 'mobile'): ?array
    {
        $credentials = $this->apiKeyManager->getCredentials(ApiKey::PROVIDER_GOOGLE_PAGESPEED);

        if (!$credentials || !$credentials['apiKey']) {
            return null;
        }

        $response = $this->httpClient->request('GET', self::API_URL, [
            'query' => [
                'url' => $url,
                'key' => $credentials['apiKey'],
                'strategy' => $strategy,
                'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return [
            'performanceScore' => $data['lighthouseResult']['categories']['performance']['score'] ?? null,
            'accessibilityScore' => $data['lighthouseResult']['categories']['accessibility']['score'] ?? null,
            'bestPracticesScore' => $data['lighthouseResult']['categories']['best-practices']['score'] ?? null,
            'seoScore' => $data['lighthouseResult']['categories']['seo']['score'] ?? null,
            'metrics' => [
                'firstContentfulPaint' => $data['lighthouseResult']['audits']['first-contentful-paint']['numericValue'] ?? null,
                'largestContentfulPaint' => $data['lighthouseResult']['audits']['largest-contentful-paint']['numericValue'] ?? null,
                'totalBlockingTime' => $data['lighthouseResult']['audits']['total-blocking-time']['numericValue'] ?? null,
                'cumulativeLayoutShift' => $data['lighthouseResult']['audits']['cumulative-layout-shift']['numericValue'] ?? null,
                'speedIndex' => $data['lighthouseResult']['audits']['speed-index']['numericValue'] ?? null,
            ],
            'rawData' => $data,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyManager->isConfigured(ApiKey::PROVIDER_GOOGLE_PAGESPEED);
    }
}
