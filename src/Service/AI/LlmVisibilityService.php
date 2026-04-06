<?php

namespace SeoExpert\Engine\Service\AI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LlmVisibilityService
{
    private const PROMPT_TEMPLATE = 'Quels sont les meilleurs %s ? Cite des exemples concrets avec leurs sites web (URLs). Donne une liste détaillée avec les points forts de chacun.';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $anthropicApiKey,
        private readonly string $openaiApiKey,
        private readonly string $googleAiApiKey,
        private readonly string $perplexityApiKey = '',
    ) {}

    /**
     * Analyze AI visibility for a domain across multiple LLMs
     */
    public function analyzeVisibility(array $keywords, string $targetDomain, string $language = 'fr'): array
    {
        $results = [
            'target_domain' => $targetDomain,
            'keywords_analyzed' => count($keywords),
            'llms' => [],
            'summary' => [],
            'domain_mentions' => [],
            'competitors' => [],
        ];

        foreach ($keywords as $keyword) {
            $prompt = sprintf(self::PROMPT_TEMPLATE, $keyword);

            // Query all available LLMs in parallel concept (sequential here for simplicity)
            $llmResponses = [];

            if ($this->anthropicApiKey) {
                try {
                    $llmResponses['claude'] = $this->queryClaude($prompt);
                } catch (\Exception $e) {
                    $this->logger->warning('Claude query failed for keyword "' . $keyword . '": ' . $e->getMessage());
                    $llmResponses['claude'] = null;
                }
            }

            if ($this->openaiApiKey) {
                try {
                    $llmResponses['chatgpt'] = $this->queryOpenAI($prompt);
                } catch (\Exception $e) {
                    $this->logger->warning('OpenAI query failed for keyword "' . $keyword . '": ' . $e->getMessage());
                    $llmResponses['chatgpt'] = null;
                }
            }

            if ($this->googleAiApiKey) {
                try {
                    $llmResponses['gemini'] = $this->queryGemini($prompt);
                } catch (\Exception $e) {
                    $this->logger->warning('Gemini query failed for keyword "' . $keyword . '": ' . $e->getMessage());
                    $llmResponses['gemini'] = null;
                }
            }

            if ($this->perplexityApiKey) {
                try {
                    $llmResponses['perplexity'] = $this->queryPerplexity($prompt);
                } catch (\Exception $e) {
                    $this->logger->warning('Perplexity query failed for keyword "' . $keyword . '": ' . $e->getMessage());
                    $llmResponses['perplexity'] = null;
                }
            }

            // Analyze responses for domain mentions
            foreach ($llmResponses as $llmName => $response) {
                if ($response === null) continue;

                $analysis = $this->analyzeResponse($response, $targetDomain, $keyword);

                if (!isset($results['llms'][$llmName])) {
                    $results['llms'][$llmName] = [
                        'name' => $this->getLlmDisplayName($llmName),
                        'keywords' => [],
                        'mention_count' => 0,
                        'total_queries' => 0,
                    ];
                }

                $results['llms'][$llmName]['keywords'][] = [
                    'keyword' => $keyword,
                    'mentioned' => $analysis['target_mentioned'],
                    'position' => $analysis['target_position'],
                    'context' => $analysis['target_context'],
                    'competitors_mentioned' => $analysis['domains_found'],
                ];

                $results['llms'][$llmName]['total_queries']++;
                if ($analysis['target_mentioned']) {
                    $results['llms'][$llmName]['mention_count']++;
                }

                // Aggregate competitor data
                foreach ($analysis['domains_found'] as $domain) {
                    $domainKey = $domain['domain'];
                    if (!isset($results['competitors'][$domainKey])) {
                        $results['competitors'][$domainKey] = [
                            'domain' => $domainKey,
                            'mentions' => 0,
                            'llms' => [],
                            'keywords' => [],
                        ];
                    }
                    $results['competitors'][$domainKey]['mentions']++;
                    if (!in_array($llmName, $results['competitors'][$domainKey]['llms'])) {
                        $results['competitors'][$domainKey]['llms'][] = $llmName;
                    }
                    if (!in_array($keyword, $results['competitors'][$domainKey]['keywords'])) {
                        $results['competitors'][$domainKey]['keywords'][] = $keyword;
                    }
                }
            }
        }

        // Sort competitors by mentions
        uasort($results['competitors'], fn($a, $b) => $b['mentions'] <=> $a['mentions']);
        $results['competitors'] = array_values($results['competitors']);

        // Build summary
        $totalLlms = count(array_filter($results['llms'], fn($l) => $l['total_queries'] > 0));
        $totalMentions = array_sum(array_column($results['llms'], 'mention_count'));
        $totalQueries = array_sum(array_column($results['llms'], 'total_queries'));

        $results['summary'] = [
            'visibility_score' => $totalQueries > 0 ? round(($totalMentions / $totalQueries) * 100) : 0,
            'total_mentions' => $totalMentions,
            'total_queries' => $totalQueries,
            'llms_queried' => $totalLlms,
            'top_competitor' => $results['competitors'][0]['domain'] ?? null,
        ];

        // Convert llms to indexed array
        $results['llms'] = array_values($results['llms']);

        return $results;
    }

    private function queryClaude(string $prompt): string
    {
        $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->anthropicApiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
            'timeout' => 30,
        ]);

        $data = $response->toArray();
        return $data['content'][0]['text'] ?? '';
    }

    private function queryOpenAI(string $prompt): string
    {
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openaiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
            'timeout' => 30,
        ]);

        $data = $response->toArray();
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function queryGemini(string $prompt): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $this->googleAiApiKey;

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 1024,
                ],
            ],
            'timeout' => 30,
        ]);

        $data = $response->toArray();
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function queryPerplexity(string $prompt): string
    {
        $response = $this->httpClient->request('POST', 'https://api.perplexity.ai/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->perplexityApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'sonar',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 1024,
            ],
            'timeout' => 45, // Perplexity does web search, can be slower
        ]);

        $data = $response->toArray();
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Analyze a LLM response to find domain mentions
     */
    private function analyzeResponse(string $response, string $targetDomain, string $keyword): array
    {
        $normalizedTarget = $this->normalizeDomain($targetDomain);
        $responseLower = mb_strtolower($response);

        // Find all domains/URLs in the response
        $domains = $this->extractDomains($response);

        // Check if target domain is mentioned
        $targetMentioned = false;
        $targetPosition = null;
        $targetContext = null;

        // Check for domain mention (with or without www, with or without protocol)
        $targetVariants = [
            $normalizedTarget,
            'www.' . $normalizedTarget,
            'https://' . $normalizedTarget,
            'https://www.' . $normalizedTarget,
            'http://' . $normalizedTarget,
        ];

        foreach ($targetVariants as $variant) {
            $pos = mb_strpos($responseLower, mb_strtolower($variant));
            if ($pos !== false) {
                $targetMentioned = true;
                // Extract surrounding context (100 chars before and after)
                $start = max(0, $pos - 100);
                $length = min(mb_strlen($response) - $start, 250);
                $targetContext = mb_substr($response, $start, $length);
                break;
            }
        }

        // Also check for brand name mention (domain without TLD)
        $brandName = explode('.', $normalizedTarget)[0];
        if (!$targetMentioned && mb_strlen($brandName) > 3) {
            $pos = mb_strpos($responseLower, mb_strtolower($brandName));
            if ($pos !== false) {
                $targetMentioned = true;
                $start = max(0, $pos - 100);
                $length = min(mb_strlen($response) - $start, 250);
                $targetContext = mb_substr($response, $start, $length);
            }
        }

        // Determine position (order of appearance among all domains)
        if ($targetMentioned) {
            $targetPosition = 1;
            foreach ($domains as $domain) {
                $domainPos = mb_strpos($responseLower, mb_strtolower($domain['domain']));
                $targetPos = mb_strpos($responseLower, mb_strtolower($normalizedTarget));
                if ($targetPos === false) {
                    $targetPos = mb_strpos($responseLower, mb_strtolower($brandName));
                }
                if ($domainPos !== false && $targetPos !== false && $domainPos < $targetPos) {
                    $targetPosition++;
                }
            }
        }

        return [
            'target_mentioned' => $targetMentioned,
            'target_position' => $targetPosition,
            'target_context' => $targetContext,
            'domains_found' => $domains,
        ];
    }

    /**
     * Extract domains from a text response
     */
    private function extractDomains(string $text): array
    {
        $domains = [];

        // Match URLs
        preg_match_all(
            '#(?:https?://)?(?:www\.)?([a-zA-Z0-9][-a-zA-Z0-9]*\.[a-zA-Z]{2,}(?:\.[a-zA-Z]{2,})?)(?:[/\s\)\],.]|$)#',
            $text,
            $matches
        );

        $seen = [];
        foreach ($matches[1] as $domain) {
            $normalized = $this->normalizeDomain($domain);
            // Skip common non-relevant domains
            if (in_array($normalized, ['example.com', 'github.com', 'wikipedia.org', 'google.com', 'youtube.com'])) {
                continue;
            }
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $domains[] = [
                    'domain' => $normalized,
                    'raw' => $domain,
                ];
            }
        }

        return $domains;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = rtrim($domain, '/');
        return mb_strtolower($domain);
    }

    private function getLlmDisplayName(string $key): string
    {
        return match ($key) {
            'claude' => 'Claude (Anthropic)',
            'chatgpt' => 'ChatGPT (OpenAI)',
            'gemini' => 'Gemini (Google)',
            'perplexity' => 'Perplexity AI',
            default => ucfirst($key),
        };
    }
}
