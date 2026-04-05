<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class W3cValidatorService
{
    private const HTML_VALIDATOR_URL = 'https://validator.w3.org/nu/';
    private const CSS_VALIDATOR_URL = 'https://jigsaw.w3.org/css-validator/validator';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {}

    public function validateHtml(string $url): ?array
    {
        $response = $this->httpClient->request('GET', self::HTML_VALIDATOR_URL, [
            'query' => [
                'doc' => $url,
                'out' => 'json',
            ],
            'headers' => [
                'User-Agent' => 'WaveRank/1.0',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        $errors = array_filter($data['messages'] ?? [], fn($m) => ($m['type'] ?? '') === 'error');
        $warnings = array_filter($data['messages'] ?? [], fn($m) => ($m['type'] ?? '') === 'warning' || ($m['type'] ?? '') === 'info');

        return [
            'isValid' => empty($errors),
            'errorCount' => count($errors),
            'warningCount' => count($warnings),
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'messages' => $data['messages'] ?? [],
        ];
    }

    public function validateCss(string $url): ?array
    {
        $response = $this->httpClient->request('GET', self::CSS_VALIDATOR_URL, [
            'query' => [
                'uri' => $url,
                'output' => 'json',
                'warning' => 'no',
            ],
            'headers' => [
                'User-Agent' => 'WaveRank/1.0',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        $result = $data['cssvalidation'] ?? [];
        $errors = $result['errors'] ?? [];
        $warnings = $result['warnings'] ?? [];

        return [
            'isValid' => empty($errors),
            'errorCount' => count($errors),
            'warningCount' => count($warnings),
            'errors' => $errors,
            'warnings' => $warnings,
            'rawData' => $data,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
