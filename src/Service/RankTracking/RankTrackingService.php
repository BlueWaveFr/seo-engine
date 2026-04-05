<?php

namespace SeoExpert\Engine\Service\RankTracking;

use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\TrackedKeyword;
use SeoExpert\Engine\Entity\KeywordRanking;
use SeoExpert\Engine\Service\DataForSeo\DataForSeoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RankTrackingService
{
    /**
     * Average CTR by SERP position (standard SEO model)
     */
    private const CTR_BY_POSITION = [
        1 => 0.317, 2 => 0.247, 3 => 0.187, 4 => 0.136, 5 => 0.098,
        6 => 0.063, 7 => 0.045, 8 => 0.031, 9 => 0.022, 10 => 0.016,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataForSeoService $dataForSeoService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Add keywords to track for a project
     */
    public function addKeywords(Project $project, array $keywords, ?string $group = null): array
    {
        $added = [];
        $repository = $this->entityManager->getRepository(TrackedKeyword::class);

        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if (empty($keyword)) {
                continue;
            }

            // Check if already exists
            $existing = $repository->findOneBy([
                'project' => $project,
                'keyword' => $keyword,
            ]);

            if ($existing) {
                continue;
            }

            $trackedKeyword = new TrackedKeyword();
            $trackedKeyword->setProject($project);
            $trackedKeyword->setKeyword($keyword);
            $trackedKeyword->setGroup($group);

            $this->entityManager->persist($trackedKeyword);
            $added[] = $trackedKeyword;
        }

        $this->entityManager->flush();

        return $added;
    }

    /**
     * Get all tracked keywords for a project
     */
    public function getTrackedKeywords(Project $project, bool $activeOnly = true): array
    {
        $repository = $this->entityManager->getRepository(TrackedKeyword::class);

        $criteria = ['project' => $project];
        if ($activeOnly) {
            $criteria['isActive'] = true;
        }

        return $repository->findBy($criteria, ['keyword' => 'ASC']);
    }

    /**
     * Get tracked keywords grouped by group name
     */
    public function getKeywordsByGroup(Project $project): array
    {
        $keywords = $this->getTrackedKeywords($project);
        $groups = [];

        foreach ($keywords as $keyword) {
            $groupName = $keyword->getGroup() ?? 'Sans groupe';
            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [];
            }
            $groups[$groupName][] = $keyword;
        }

        return $groups;
    }

    /**
     * Check rankings for all active keywords of a project
     */
    public function checkRankings(Project $project): array
    {
        $keywords = $this->getTrackedKeywords($project, true);
        $results = [];

        // Group keywords for batch processing
        $keywordTexts = array_map(fn($k) => $k->getKeyword(), $keywords);

        if (empty($keywordTexts)) {
            return [];
        }

        // Get location code for the project
        $locationCode = $this->getLocationCode($project->getTargetCountry());
        $languageCode = $project->getTargetLanguage();
        $domain = $this->extractDomain($project->getWebsiteUrl());

        // Check each keyword position
        foreach ($keywords as $trackedKeyword) {
            try {
                $result = $this->checkKeywordRanking(
                    $trackedKeyword,
                    $domain,
                    $locationCode,
                    $languageCode
                );
                $results[] = $result;
            } catch (\Exception $e) {
                $this->logger->error('Failed to check ranking for keyword: ' . $trackedKeyword->getKeyword(), [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        return $results;
    }

    /**
     * Check ranking for a single keyword
     */
    public function checkKeywordRanking(
        TrackedKeyword $trackedKeyword,
        string $domain,
        string $locationCode,
        string $languageCode
    ): TrackedKeyword {
        $keyword = $trackedKeyword->getKeyword();

        // Get SERP results
        $serpData = $this->dataForSeoService->getSerpAnalysis($keyword, $locationCode, $languageCode);

        $position = null;
        $rankingUrl = null;
        $serpFeatures = [];

        // Find our position in SERP
        if (!empty($serpData['results'])) {
            foreach ($serpData['results'] as $index => $result) {
                if (isset($result['url']) && $this->urlMatchesDomain($result['url'], $domain)) {
                    $position = $index + 1;
                    $rankingUrl = $result['url'];
                    break;
                }
            }
        }

        // Extract SERP features
        if (!empty($serpData['serp_features'])) {
            $serpFeatures = $serpData['serp_features'];
        }

        // Update tracked keyword
        $previousPosition = $trackedKeyword->getCurrentPosition();
        $trackedKeyword->setPreviousPosition($previousPosition);
        $trackedKeyword->setCurrentPosition($position);
        $trackedKeyword->setRankingUrl($rankingUrl);
        $trackedKeyword->setSerpFeatures($serpFeatures);
        $trackedKeyword->setLastCheckedAt(new \DateTimeImmutable());

        // Update best/worst positions
        if ($position !== null) {
            if ($trackedKeyword->getBestPosition() === null || $position < $trackedKeyword->getBestPosition()) {
                $trackedKeyword->setBestPosition($position);
            }
            if ($trackedKeyword->getWorstPosition() === null || $position > $trackedKeyword->getWorstPosition()) {
                $trackedKeyword->setWorstPosition($position);
            }

            // Add to history
            $trackedKeyword->addPositionToHistory($position, new \DateTimeImmutable());
        }

        // Get keyword metrics if not already set
        if ($trackedKeyword->getSearchVolume() === null) {
            $this->enrichKeywordData($trackedKeyword, $locationCode, $languageCode);
        }

        // Create ranking snapshot
        $ranking = new KeywordRanking();
        $ranking->setProject($trackedKeyword->getProject());
        $ranking->setKeyword($keyword);
        $ranking->setPosition($position);
        $ranking->setPreviousPosition($previousPosition);
        $ranking->setPositionChange($previousPosition !== null && $position !== null ? $previousPosition - $position : null);
        $ranking->setUrl($rankingUrl);
        $ranking->setCountry($trackedKeyword->getProject()->getTargetCountry());
        $ranking->setLanguage($languageCode);
        $ranking->setSearchVolume($trackedKeyword->getSearchVolume());
        $ranking->setCpc($trackedKeyword->getCpc());
        $ranking->setCompetition($trackedKeyword->getCompetition());
        $ranking->setSerpFeatures($serpFeatures);

        $this->entityManager->persist($ranking);

        return $trackedKeyword;
    }

    /**
     * Enrich keyword with search volume, CPC, etc.
     */
    public function enrichKeywordData(
        TrackedKeyword $trackedKeyword,
        string $locationCode,
        string $languageCode
    ): void {
        try {
            $data = $this->dataForSeoService->getKeywordsData(
                [$trackedKeyword->getKeyword()],
                $locationCode,
                $languageCode
            );

            if (!empty($data[0])) {
                $trackedKeyword->setSearchVolume($data[0]['search_volume'] ?? null);
                $trackedKeyword->setCpc($data[0]['cpc'] ?? null);
                $competitionRaw = $data[0]['competition'] ?? null;
                $trackedKeyword->setCompetition($competitionRaw !== null ? (float) $competitionRaw : null);

                // Set difficulty level
                $competition = (float) ($data[0]['competition'] ?? 0);
                if ($competition < 0.3) {
                    $trackedKeyword->setDifficulty('easy');
                } elseif ($competition < 0.6) {
                    $trackedKeyword->setDifficulty('medium');
                } else {
                    $trackedKeyword->setDifficulty('hard');
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to enrich keyword data: ' . $e->getMessage());
        }
    }

    /**
     * Get ranking history for a keyword
     */
    public function getRankingHistory(TrackedKeyword $trackedKeyword, int $days = 30): array
    {
        $repository = $this->entityManager->getRepository(KeywordRanking::class);

        $since = new \DateTimeImmutable("-{$days} days");

        return $repository->createQueryBuilder('r')
            ->where('r.project = :project')
            ->andWhere('r.keyword = :keyword')
            ->andWhere('r.checkedAt >= :since')
            ->setParameter('project', $trackedKeyword->getProject())
            ->setParameter('keyword', $trackedKeyword->getKeyword())
            ->setParameter('since', $since)
            ->orderBy('r.checkedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get project ranking summary
     */
    public function getProjectSummary(Project $project): array
    {
        $keywords = $this->getTrackedKeywords($project);

        $summary = [
            'total_keywords' => count($keywords),
            'ranked_keywords' => 0,
            'not_ranked' => 0,
            'top_3' => 0,
            'top_10' => 0,
            'top_20' => 0,
            'top_100' => 0,
            'improved' => 0,
            'declined' => 0,
            'stable' => 0,
            'average_position' => null,
            'visibility_score' => 0,
        ];

        $totalPositions = 0;
        $rankedCount = 0;

        foreach ($keywords as $keyword) {
            $position = $keyword->getCurrentPosition();
            $change = $keyword->getPositionChange();

            if ($position !== null) {
                $summary['ranked_keywords']++;
                $rankedCount++;
                $totalPositions += $position;

                if ($position <= 3) $summary['top_3']++;
                if ($position <= 10) $summary['top_10']++;
                if ($position <= 20) $summary['top_20']++;
                if ($position <= 100) $summary['top_100']++;

                // Calculate visibility score (weighted by position)
                $summary['visibility_score'] += max(0, 100 - $position) / 100;
            } else {
                $summary['not_ranked']++;
            }

            if ($change !== null) {
                if ($change > 0) {
                    $summary['improved']++;
                } elseif ($change < 0) {
                    $summary['declined']++;
                } else {
                    $summary['stable']++;
                }
            }
        }

        if ($rankedCount > 0) {
            $summary['average_position'] = round($totalPositions / $rankedCount, 1);
            $summary['visibility_score'] = round(($summary['visibility_score'] / count($keywords)) * 100, 1);
        }

        return $summary;
    }

    /**
     * Get position distribution
     */
    public function getPositionDistribution(Project $project): array
    {
        $keywords = $this->getTrackedKeywords($project);

        $distribution = [
            '1-3' => 0,
            '4-10' => 0,
            '11-20' => 0,
            '21-50' => 0,
            '51-100' => 0,
            '100+' => 0,
            'not_ranked' => 0,
        ];

        foreach ($keywords as $keyword) {
            $position = $keyword->getCurrentPosition();

            if ($position === null) {
                $distribution['not_ranked']++;
            } elseif ($position <= 3) {
                $distribution['1-3']++;
            } elseif ($position <= 10) {
                $distribution['4-10']++;
            } elseif ($position <= 20) {
                $distribution['11-20']++;
            } elseif ($position <= 50) {
                $distribution['21-50']++;
            } elseif ($position <= 100) {
                $distribution['51-100']++;
            } else {
                $distribution['100+']++;
            }
        }

        return $distribution;
    }

    /**
     * Import keywords from project's existing keywords
     */
    public function importFromProjectKeywords(Project $project): array
    {
        $existingKeywords = $project->getKeywords();

        if (empty($existingKeywords)) {
            return [];
        }

        return $this->addKeywords($project, $existingKeywords, 'Principal');
    }

    /**
     * Delete a tracked keyword
     */
    public function deleteKeyword(TrackedKeyword $trackedKeyword): void
    {
        // Also delete history
        $this->entityManager->createQueryBuilder()
            ->delete(KeywordRanking::class, 'r')
            ->where('r.project = :project')
            ->andWhere('r.keyword = :keyword')
            ->setParameter('project', $trackedKeyword->getProject())
            ->setParameter('keyword', $trackedKeyword->getKeyword())
            ->getQuery()
            ->execute();

        $this->entityManager->remove($trackedKeyword);
        $this->entityManager->flush();
    }

    /**
     * Estimate organic traffic for a project based on tracked keywords.
     * Formula: traffic = search_volume × CTR(position) for each keyword.
     */
    public function estimateOrganicTraffic(Project $project): array
    {
        $keywords = $this->getTrackedKeywords($project);

        $totalTraffic = 0;
        $totalVolume = 0;
        $rankedCount = 0;
        $byKeyword = [];
        $byRange = [
            '1-3' => ['keywords' => 0, 'traffic' => 0, 'volume' => 0],
            '4-10' => ['keywords' => 0, 'traffic' => 0, 'volume' => 0],
            '11-20' => ['keywords' => 0, 'traffic' => 0, 'volume' => 0],
            '21-50' => ['keywords' => 0, 'traffic' => 0, 'volume' => 0],
            '51-100' => ['keywords' => 0, 'traffic' => 0, 'volume' => 0],
        ];

        foreach ($keywords as $kw) {
            $position = $kw->getCurrentPosition();
            $volume = $kw->getSearchVolume();
            $ctr = $this->getCtrForPosition($position);
            $estimatedTraffic = $volume !== null ? (int) round($volume * $ctr) : 0;

            if ($volume !== null) {
                $totalVolume += $volume;
            }

            $totalTraffic += $estimatedTraffic;

            if ($position !== null) {
                $rankedCount++;
            }

            $byKeyword[] = [
                'keyword' => $kw->getKeyword(),
                'position' => $position,
                'search_volume' => $volume,
                'ctr' => round($ctr * 100, 1),
                'estimated_traffic' => $estimatedTraffic,
                'url' => $kw->getRankingUrl(),
            ];

            // Classify by position range
            if ($position !== null && $volume !== null) {
                $range = match (true) {
                    $position <= 3 => '1-3',
                    $position <= 10 => '4-10',
                    $position <= 20 => '11-20',
                    $position <= 50 => '21-50',
                    $position <= 100 => '51-100',
                    default => null,
                };
                if ($range !== null) {
                    $byRange[$range]['keywords']++;
                    $byRange[$range]['traffic'] += $estimatedTraffic;
                    $byRange[$range]['volume'] += $volume;
                }
            }
        }

        // Sort by estimated traffic descending
        usort($byKeyword, fn($a, $b) => $b['estimated_traffic'] <=> $a['estimated_traffic']);

        $averageCtr = $rankedCount > 0 && $totalVolume > 0
            ? round(($totalTraffic / $totalVolume) * 100, 1)
            : 0;

        return [
            'total_estimated_monthly_traffic' => $totalTraffic,
            'total_search_volume' => $totalVolume,
            'average_ctr' => $averageCtr,
            'keyword_count' => count($keywords),
            'ranked_keyword_count' => $rankedCount,
            'by_keyword' => $byKeyword,
            'by_position_range' => $byRange,
        ];
    }

    /**
     * Get CTR for a given SERP position.
     */
    private function getCtrForPosition(?int $position): float
    {
        if ($position === null || $position <= 0) {
            return 0.0;
        }

        if (isset(self::CTR_BY_POSITION[$position])) {
            return self::CTR_BY_POSITION[$position];
        }

        // Falloff for positions > 10
        return match (true) {
            $position <= 20 => 0.01,
            $position <= 50 => 0.005,
            $position <= 100 => 0.001,
            default => 0.0,
        };
    }

    /**
     * Get location code from country code
     */
    private function getLocationCode(string $countryCode): string
    {
        $locationCodes = [
            'FR' => '2250',
            'US' => '2840',
            'GB' => '2826',
            'DE' => '2276',
            'ES' => '2724',
            'IT' => '2380',
            'BE' => '2056',
            'CH' => '2756',
            'CA' => '2124',
        ];

        return $locationCodes[strtoupper($countryCode)] ?? '2250';
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain(?string $url): string
    {
        if (!$url) {
            return '';
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Remove www prefix
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Check if URL matches domain
     */
    private function urlMatchesDomain(string $url, string $domain): bool
    {
        $urlDomain = $this->extractDomain($url);
        return $urlDomain === $domain || str_ends_with($urlDomain, '.' . $domain);
    }
}
