<?php

namespace SeoExpert\Engine\Service\Google;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PageSpeedInsightsService
{
    private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    /**
     * Analyze a URL with PageSpeed Insights
     * @param string $url The URL to analyze
     * @param string $strategy 'mobile' or 'desktop'
     * @param array $categories Categories to analyze: performance, accessibility, best-practices, seo
     */
    public function analyze(
        string $url,
        string $strategy = 'mobile',
        array $categories = ['performance', 'accessibility', 'best-practices', 'seo']
    ): array {
        try {
            // Build query string manually to handle repeated 'category' params correctly
            // Google API expects: category=PERFORMANCE&category=ACCESSIBILITY (not category[0]=...)
            $queryParts = [
                'url=' . urlencode($url),
                'key=' . urlencode($this->apiKey),
                'strategy=' . urlencode($strategy),
            ];

            // Add categories (API expects uppercase with underscores)
            foreach ($categories as $category) {
                // Convert 'best-practices' to 'BEST_PRACTICES'
                $apiCategory = strtoupper(str_replace('-', '_', $category));
                $queryParts[] = 'category=' . $apiCategory;
            }

            $fullUrl = self::API_URL . '?' . implode('&', $queryParts);

            $response = $this->httpClient->request('GET', $fullUrl, [
                'timeout' => 120, // PageSpeed can take a while
            ]);

            $data = $response->toArray();

            return $this->parseResponse($data, $strategy);
        } catch (\Exception $e) {
            $this->logger->error('PageSpeed Insights API error: ' . $e->getMessage(), [
                'url' => $url,
                'strategy' => $strategy,
            ]);
            throw $e;
        }
    }

    /**
     * Analyze both mobile and desktop
     * @param bool $includeDesktop Whether to include desktop analysis (adds ~60s)
     */
    public function analyzeFullReport(string $url, bool $includeDesktop = false): array
    {
        $mobile = $this->analyze($url, 'mobile');

        $result = [
            'url' => $url,
            'mobile' => $mobile,
            'analyzed_at' => (new \DateTimeImmutable())->format('c'),
        ];

        // Desktop is optional (adds significant time and Google uses mobile-first indexing)
        if ($includeDesktop) {
            $result['desktop'] = $this->analyze($url, 'desktop');
        }

        return $result;
    }

    /**
     * Analyze mobile only (faster, recommended for SEO audits)
     */
    public function analyzeMobileOnly(string $url): array
    {
        return $this->analyzeFullReport($url, false);
    }

    /**
     * Parse the PageSpeed API response
     */
    private function parseResponse(array $data, string $strategy): array
    {
        $lighthouseResult = $data['lighthouseResult'] ?? [];
        $loadingExperience = $data['loadingExperience'] ?? [];

        // Core Web Vitals from field data (real user data)
        $fieldMetrics = $this->extractFieldMetrics($loadingExperience);

        // Lab data from Lighthouse
        $labMetrics = $this->extractLabMetrics($lighthouseResult);

        // Category scores
        $categories = $this->extractCategoryScores($lighthouseResult);

        // Opportunities and diagnostics
        $audits = $this->extractAudits($lighthouseResult);

        return [
            'strategy' => $strategy,
            'final_url' => $lighthouseResult['finalUrl'] ?? null,
            'fetch_time' => $lighthouseResult['fetchTime'] ?? null,
            'scores' => $categories,
            'core_web_vitals' => [
                'field' => $fieldMetrics,
                'lab' => $labMetrics,
            ],
            'opportunities' => $audits['opportunities'],
            'diagnostics' => $audits['diagnostics'],
            'passed_audits' => $audits['passed'],
        ];
    }

    /**
     * Extract Core Web Vitals from field data (real user metrics)
     */
    private function extractFieldMetrics(array $loadingExperience): array
    {
        $metrics = $loadingExperience['metrics'] ?? [];

        return [
            'lcp' => $this->extractFieldMetric($metrics, 'LARGEST_CONTENTFUL_PAINT_MS'),
            'fid' => $this->extractFieldMetric($metrics, 'FIRST_INPUT_DELAY_MS'),
            'inp' => $this->extractFieldMetric($metrics, 'INTERACTION_TO_NEXT_PAINT'),
            'cls' => $this->extractFieldMetric($metrics, 'CUMULATIVE_LAYOUT_SHIFT_SCORE'),
            'fcp' => $this->extractFieldMetric($metrics, 'FIRST_CONTENTFUL_PAINT_MS'),
            'ttfb' => $this->extractFieldMetric($metrics, 'EXPERIMENTAL_TIME_TO_FIRST_BYTE'),
            'overall_category' => $loadingExperience['overall_category'] ?? null,
        ];
    }

    /**
     * Extract a single field metric
     */
    private function extractFieldMetric(array $metrics, string $key): ?array
    {
        if (!isset($metrics[$key])) {
            return null;
        }

        $metric = $metrics[$key];
        return [
            'percentile' => $metric['percentile'] ?? null,
            'category' => $metric['category'] ?? null,
            'distributions' => $metric['distributions'] ?? [],
        ];
    }

    /**
     * Extract lab metrics from Lighthouse
     */
    private function extractLabMetrics(array $lighthouseResult): array
    {
        $audits = $lighthouseResult['audits'] ?? [];

        return [
            'lcp' => [
                'value' => $audits['largest-contentful-paint']['numericValue'] ?? null,
                'display' => $audits['largest-contentful-paint']['displayValue'] ?? null,
                'score' => $audits['largest-contentful-paint']['score'] ?? null,
            ],
            'fcp' => [
                'value' => $audits['first-contentful-paint']['numericValue'] ?? null,
                'display' => $audits['first-contentful-paint']['displayValue'] ?? null,
                'score' => $audits['first-contentful-paint']['score'] ?? null,
            ],
            'cls' => [
                'value' => $audits['cumulative-layout-shift']['numericValue'] ?? null,
                'display' => $audits['cumulative-layout-shift']['displayValue'] ?? null,
                'score' => $audits['cumulative-layout-shift']['score'] ?? null,
            ],
            'tbt' => [
                'value' => $audits['total-blocking-time']['numericValue'] ?? null,
                'display' => $audits['total-blocking-time']['displayValue'] ?? null,
                'score' => $audits['total-blocking-time']['score'] ?? null,
            ],
            'si' => [
                'value' => $audits['speed-index']['numericValue'] ?? null,
                'display' => $audits['speed-index']['displayValue'] ?? null,
                'score' => $audits['speed-index']['score'] ?? null,
            ],
            'tti' => [
                'value' => $audits['interactive']['numericValue'] ?? null,
                'display' => $audits['interactive']['displayValue'] ?? null,
                'score' => $audits['interactive']['score'] ?? null,
            ],
        ];
    }

    /**
     * Extract category scores
     */
    private function extractCategoryScores(array $lighthouseResult): array
    {
        $categories = $lighthouseResult['categories'] ?? [];

        $scores = [];
        foreach ($categories as $key => $category) {
            $scores[$key] = [
                'score' => isset($category['score']) ? round($category['score'] * 100) : null,
                'title' => $category['title'] ?? null,
            ];
        }

        return $scores;
    }

    /**
     * Extract audits (opportunities and diagnostics)
     */
    private function extractAudits(array $lighthouseResult): array
    {
        $audits = $lighthouseResult['audits'] ?? [];
        $categories = $lighthouseResult['categories'] ?? [];

        $opportunities = [];
        $diagnostics = [];
        $passed = [];

        // Get performance category audit refs
        $performanceRefs = $categories['performance']['auditRefs'] ?? [];

        foreach ($performanceRefs as $ref) {
            $auditId = $ref['id'];
            $audit = $audits[$auditId] ?? null;

            if (!$audit) {
                continue;
            }

            $auditData = [
                'id' => $auditId,
                'title' => $audit['title'] ?? null,
                'description' => $audit['description'] ?? null,
                'score' => $audit['score'] ?? null,
                'display_value' => $audit['displayValue'] ?? null,
                'numeric_value' => $audit['numericValue'] ?? null,
                'weight' => $ref['weight'] ?? 0,
            ];

            // Categorize by group and score
            if (($ref['group'] ?? '') === 'load-opportunities' && ($audit['score'] ?? 1) < 1) {
                $auditData['savings_ms'] = $audit['numericValue'] ?? 0;
                $opportunities[] = $auditData;
            } elseif (($ref['group'] ?? '') === 'diagnostics' && ($audit['score'] ?? 1) < 1) {
                $diagnostics[] = $auditData;
            } elseif (($audit['score'] ?? 0) >= 0.9) {
                $passed[] = $auditData;
            }
        }

        // Sort opportunities by potential savings
        usort($opportunities, fn($a, $b) => ($b['savings_ms'] ?? 0) <=> ($a['savings_ms'] ?? 0));

        return [
            'opportunities' => $opportunities,
            'diagnostics' => $diagnostics,
            'passed' => $passed,
        ];
    }

    /**
     * Get a summary suitable for comparison
     */
    public function getSummary(string $url): array
    {
        $mobile = $this->analyze($url, 'mobile', ['performance']);
        $desktop = $this->analyze($url, 'desktop', ['performance']);

        return [
            'url' => $url,
            'mobile' => [
                'performance_score' => $mobile['scores']['performance']['score'] ?? null,
                'lcp' => $mobile['core_web_vitals']['lab']['lcp']['value'] ?? null,
                'fcp' => $mobile['core_web_vitals']['lab']['fcp']['value'] ?? null,
                'cls' => $mobile['core_web_vitals']['lab']['cls']['value'] ?? null,
                'tbt' => $mobile['core_web_vitals']['lab']['tbt']['value'] ?? null,
            ],
            'desktop' => [
                'performance_score' => $desktop['scores']['performance']['score'] ?? null,
                'lcp' => $desktop['core_web_vitals']['lab']['lcp']['value'] ?? null,
                'fcp' => $desktop['core_web_vitals']['lab']['fcp']['value'] ?? null,
                'cls' => $desktop['core_web_vitals']['lab']['cls']['value'] ?? null,
                'tbt' => $desktop['core_web_vitals']['lab']['tbt']['value'] ?? null,
            ],
            'analyzed_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
