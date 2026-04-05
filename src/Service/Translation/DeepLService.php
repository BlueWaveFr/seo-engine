<?php

namespace SeoExpert\Engine\Service\Translation;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DeepLService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $apiUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        string $deeplApiKey = '',
        string $deeplApiUrl = 'https://api-free.deepl.com/v2'
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->apiKey = $deeplApiKey;
        $this->apiUrl = $deeplApiUrl;
    }

    /**
     * Translate text from source language to target language
     */
    public function translate(string $text, string $targetLang, string $sourceLang = 'FR'): ?string
    {
        if (empty($this->apiKey)) {
            $this->logger->warning('DeepL API key not configured');
            return null;
        }

        if (empty(trim($text))) {
            return $text;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/translate', [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => [$text],
                    'source_lang' => strtoupper($sourceLang),
                    'target_lang' => strtoupper($targetLang),
                    'tag_handling' => 'html',
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['translations'][0]['text'])) {
                return $data['translations'][0]['text'];
            }

            $this->logger->error('DeepL response format unexpected', ['response' => $data]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('DeepL translation failed', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);
            return null;
        }
    }

    /**
     * Translate multiple texts at once
     */
    public function translateBatch(array $texts, string $targetLang, string $sourceLang = 'FR'): array
    {
        if (empty($this->apiKey)) {
            $this->logger->warning('DeepL API key not configured');
            return array_fill(0, count($texts), null);
        }

        // Filter out empty texts but keep track of positions
        $nonEmptyTexts = [];
        $positions = [];
        foreach ($texts as $index => $text) {
            if (!empty(trim($text))) {
                $nonEmptyTexts[] = $text;
                $positions[] = $index;
            }
        }

        if (empty($nonEmptyTexts)) {
            return $texts;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/translate', [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $nonEmptyTexts,
                    'source_lang' => strtoupper($sourceLang),
                    'target_lang' => strtoupper($targetLang),
                    'tag_handling' => 'html',
                ],
            ]);

            $data = $response->toArray();

            // Reconstruct results array with translations at correct positions
            $results = $texts; // Start with original texts
            if (isset($data['translations'])) {
                foreach ($data['translations'] as $i => $translation) {
                    if (isset($positions[$i])) {
                        $results[$positions[$i]] = $translation['text'] ?? $texts[$positions[$i]];
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('DeepL batch translation failed', [
                'error' => $e->getMessage(),
                'text_count' => count($texts),
            ]);
            return array_fill(0, count($texts), null);
        }
    }

    /**
     * Check if API is configured and working
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get usage statistics from DeepL API
     */
    public function getUsage(): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . '/usage', [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                ],
            ]);

            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get DeepL usage', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
