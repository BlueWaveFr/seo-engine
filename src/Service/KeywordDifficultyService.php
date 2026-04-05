<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Service\DataForSeo\DataForSeoService;
use SeoExpert\Engine\Service\ThirdParty\MozService;
use Psr\Log\LoggerInterface;

class KeywordDifficultyService
{
    private const POSITION_WEIGHTS = [
        1 => 0.317, 2 => 0.247, 3 => 0.187, 4 => 0.136, 5 => 0.098,
        6 => 0.063, 7 => 0.045, 8 => 0.031, 9 => 0.022, 10 => 0.016,
    ];

    public function __construct(
        private readonly DataForSeoService $dataForSeoService,
        private readonly MozService $mozService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculate enhanced keyword difficulty by combining DataForSeo difficulty
     * with Moz DA of top 10 SERP competitors.
     *
     * Score = (dataforseo_difficulty × 0.5) + (weighted_average_da × 0.5)
     */
    public function calculateEnhancedDifficulty(
        string $keyword,
        string $locationCode = '2250',
        string $languageCode = 'fr'
    ): array {
        // 1. Get DataForSeo difficulty
        $dataforseoScore = null;
        try {
            $diffData = $this->dataForSeoService->getKeywordDifficulty([$keyword], $locationCode, $languageCode);
            $dataforseoScore = $diffData[$keyword]['difficulty'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('DataForSeo difficulty failed: ' . $e->getMessage());
        }

        // 2. Get SERP top 10
        $serpCompetitors = [];
        try {
            $serpData = $this->dataForSeoService->getSerpAnalysis($keyword, $locationCode, $languageCode);
            $results = $serpData['results'] ?? [];

            foreach (array_slice($results, 0, 10) as $index => $result) {
                $serpCompetitors[] = [
                    'position' => $index + 1,
                    'domain' => $this->extractDomain($result['url'] ?? ''),
                    'url' => $result['url'] ?? '',
                    'title' => $result['title'] ?? '',
                    'da' => null,
                    'pa' => null,
                ];
            }
        } catch (\Exception $e) {
            $this->logger->warning('SERP analysis failed: ' . $e->getMessage());
        }

        // 3. Enrich with Moz DA/PA
        $mozAvailable = false;
        if (!empty($serpCompetitors) && $this->mozService->isConfigured()) {
            $mozAvailable = true;
            $urls = array_column($serpCompetitors, 'url');

            try {
                $mozData = $this->mozService->getBulkUrlMetrics($urls);

                foreach ($serpCompetitors as $i => &$competitor) {
                    $url = $competitor['url'];
                    if (isset($mozData[$url])) {
                        $competitor['da'] = $mozData[$url]['domain_authority'] ?? null;
                        $competitor['pa'] = $mozData[$url]['page_authority'] ?? null;
                    }
                }
                unset($competitor);
            } catch (\Exception $e) {
                $this->logger->warning('Moz bulk metrics failed: ' . $e->getMessage());
                $mozAvailable = false;
            }
        }

        // 4. Compute weighted average DA
        $weightedDa = $this->computeWeightedDa($serpCompetitors);

        // 5. Compute custom score
        $customScore = null;
        if ($dataforseoScore !== null && $weightedDa !== null) {
            $customScore = round(($dataforseoScore * 0.5) + ($weightedDa * 0.5), 1);
        } elseif ($dataforseoScore !== null) {
            $customScore = (float) $dataforseoScore;
        } elseif ($weightedDa !== null) {
            $customScore = $weightedDa;
        }

        return [
            'keyword' => $keyword,
            'dataforseo_difficulty' => $dataforseoScore,
            'custom_difficulty' => $customScore,
            'difficulty_label' => $customScore !== null ? $this->getDifficultyLabel($customScore) : null,
            'weighted_average_da' => $weightedDa,
            'serp_competitors' => $serpCompetitors,
            'moz_available' => $mozAvailable,
        ];
    }

    private function computeWeightedDa(array $competitors): ?float
    {
        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($competitors as $competitor) {
            $da = $competitor['da'];
            $position = $competitor['position'];

            if ($da !== null && isset(self::POSITION_WEIGHTS[$position])) {
                $weight = self::POSITION_WEIGHTS[$position];
                $weightedSum += $da * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight <= 0) {
            return null;
        }

        return round($weightedSum / $totalWeight, 1);
    }

    private function getDifficultyLabel(float $score): string
    {
        return match (true) {
            $score <= 20 => 'very_easy',
            $score <= 40 => 'easy',
            $score <= 60 => 'medium',
            $score <= 80 => 'hard',
            default => 'very_hard',
        };
    }

    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        return preg_replace('/^www\./', '', $host);
    }
}
