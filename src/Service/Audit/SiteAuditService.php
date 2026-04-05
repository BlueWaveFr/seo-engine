<?php

namespace SeoExpert\Engine\Service\Audit;

use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\SiteAudit;
use SeoExpert\Engine\Service\Google\PageSpeedInsightsService;
use SeoExpert\Engine\Service\Google\SafeBrowsingService;
use SeoExpert\Engine\Service\Google\CustomSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class SiteAuditService
{
    public function __construct(
        private readonly SiteCrawlerService $crawler,
        private readonly PageSpeedInsightsService $pageSpeed,
        private readonly SafeBrowsingService $safeBrowsing,
        private readonly CustomSearchService $customSearch,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create and start a new audit for a project
     */
    public function createAudit(Project $project, ?string $url = null): SiteAudit
    {
        $auditUrl = $url ?? $project->getWebsiteUrl();

        if (!$auditUrl) {
            throw new \InvalidArgumentException('No URL provided and project has no website URL');
        }

        $audit = new SiteAudit();
        $audit->setProject($project);
        $audit->setUrl($auditUrl);

        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        return $audit;
    }

    /**
     * Run the full audit process
     */
    public function runAudit(SiteAudit $audit): SiteAudit
    {
        $startTime = microtime(true);
        $audit->markAsRunning();
        $this->entityManager->flush();

        $allIssues = [];

        try {
            $url = $audit->getUrl();
            $domain = parse_url($url, PHP_URL_HOST);

            // 1. Run site crawler (limited pages for faster execution)
            $this->logger->info('Starting crawler audit', ['url' => $url]);
            try {
                $crawlerData = $this->crawler->auditSite($url, 15);
                $audit->setCrawlerData($crawlerData);

                // Extract scores
                $audit->setTechnicalScore($crawlerData['scores']['technical'] ?? null);
                $audit->setSeoScore($crawlerData['scores']['seo'] ?? null);
                $audit->setSecurityScore($crawlerData['scores']['security'] ?? null);

                // Collect issues
                foreach ($crawlerData['issues'] ?? [] as $issue) {
                    $allIssues[] = array_merge($issue, ['source' => 'crawler']);
                }
                foreach ($crawlerData['seo']['homepage']['issues'] ?? [] as $issue) {
                    $allIssues[] = array_merge($issue, ['source' => 'seo']);
                }
            } catch (\Exception $e) {
                $this->logger->error('Crawler audit failed', ['error' => $e->getMessage()]);
                $allIssues[] = ['type' => 'critical', 'message' => 'Crawler audit failed: ' . $e->getMessage(), 'source' => 'crawler'];
            }

            // 2. Run PageSpeed Insights (mobile only for faster execution - Google uses mobile-first indexing)
            $this->logger->info('Starting PageSpeed audit', ['url' => $url]);
            try {
                $pageSpeedData = $this->pageSpeed->analyzeFullReport($url, false); // Mobile only
                $audit->setPageSpeedData($pageSpeedData);

                // Extract mobile performance score and Core Web Vitals
                $mobileData = $pageSpeedData['mobile'] ?? [];
                $desktopData = $pageSpeedData['desktop'] ?? []; // May be empty

                $audit->setPerformanceScoreMobile($mobileData['scores']['performance']['score'] ?? null);
                if (!empty($desktopData)) {
                    $audit->setPerformanceScoreDesktop($desktopData['scores']['performance']['score'] ?? null);
                }

                // Core Web Vitals (mobile)
                $labMetrics = $mobileData['core_web_vitals']['lab'] ?? [];
                $audit->setLcpMobile($labMetrics['lcp']['value'] ?? null);
                $audit->setFcpMobile($labMetrics['fcp']['value'] ?? null);
                $audit->setClsMobile($labMetrics['cls']['value'] ?? null);
                $audit->setTbtMobile($labMetrics['tbt']['value'] ?? null);

                // Add performance issues
                foreach ($mobileData['opportunities'] ?? [] as $opportunity) {
                    if (($opportunity['savings_ms'] ?? 0) > 500) {
                        $allIssues[] = [
                            'type' => 'warning',
                            'message' => $opportunity['title'] . ' - Potential savings: ' . round(($opportunity['savings_ms'] ?? 0) / 1000, 1) . 's',
                            'source' => 'performance',
                        ];
                    }
                }

                // Check Core Web Vitals thresholds
                $lcp = $labMetrics['lcp']['value'] ?? null;
                $cls = $labMetrics['cls']['value'] ?? null;
                $tbt = $labMetrics['tbt']['value'] ?? null;

                if ($lcp && $lcp > 4000) {
                    $allIssues[] = ['type' => 'critical', 'message' => 'LCP is poor (' . round($lcp / 1000, 1) . 's). Target: < 2.5s', 'source' => 'performance'];
                } elseif ($lcp && $lcp > 2500) {
                    $allIssues[] = ['type' => 'warning', 'message' => 'LCP needs improvement (' . round($lcp / 1000, 1) . 's). Target: < 2.5s', 'source' => 'performance'];
                }

                if ($cls && $cls > 0.25) {
                    $allIssues[] = ['type' => 'critical', 'message' => 'CLS is poor (' . round($cls, 3) . '). Target: < 0.1', 'source' => 'performance'];
                } elseif ($cls && $cls > 0.1) {
                    $allIssues[] = ['type' => 'warning', 'message' => 'CLS needs improvement (' . round($cls, 3) . '). Target: < 0.1', 'source' => 'performance'];
                }

                if ($tbt && $tbt > 600) {
                    $allIssues[] = ['type' => 'critical', 'message' => 'TBT is high (' . round($tbt) . 'ms). Target: < 200ms', 'source' => 'performance'];
                } elseif ($tbt && $tbt > 200) {
                    $allIssues[] = ['type' => 'warning', 'message' => 'TBT needs improvement (' . round($tbt) . 'ms). Target: < 200ms', 'source' => 'performance'];
                }

            } catch (\Exception $e) {
                $this->logger->error('PageSpeed audit failed', ['error' => $e->getMessage()]);
                $allIssues[] = ['type' => 'warning', 'message' => 'PageSpeed audit failed: ' . $e->getMessage(), 'source' => 'performance'];
            }

            // 3. Check Safe Browsing
            $this->logger->info('Starting Safe Browsing check', ['domain' => $domain]);
            try {
                $safeBrowsingData = $this->safeBrowsing->getDomainReport($domain);
                $audit->setSafeBrowsingData($safeBrowsingData);
                $audit->setIsSafe($safeBrowsingData['safe'] ?? true);

                if (!$safeBrowsingData['safe']) {
                    $allIssues[] = [
                        'type' => 'critical',
                        'message' => 'SECURITY ALERT: Site is flagged by Google Safe Browsing',
                        'source' => 'security',
                    ];
                    foreach ($safeBrowsingData['threats'] ?? [] as $threat) {
                        $allIssues[] = [
                            'type' => 'critical',
                            'message' => 'Threat: ' . SafeBrowsingService::getThreatDescription($threat['type']),
                            'source' => 'security',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Safe Browsing check failed', ['error' => $e->getMessage()]);
            }

            // 4. Check indexation
            $this->logger->info('Starting indexation check', ['domain' => $domain]);
            try {
                $indexationData = $this->customSearch->getIndexedPagesCount($domain);
                $audit->setIndexationData($indexationData);
                $audit->setIndexedPages($indexationData['indexed_pages'] ?? null);

                $indexedPages = $indexationData['indexed_pages'] ?? 0;
                if ($indexedPages === 0) {
                    $allIssues[] = [
                        'type' => 'critical',
                        'message' => 'No pages appear to be indexed by Google',
                        'source' => 'indexation',
                    ];
                } elseif ($indexedPages < 10) {
                    $allIssues[] = [
                        'type' => 'warning',
                        'message' => "Only {$indexedPages} pages indexed by Google",
                        'source' => 'indexation',
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->error('Indexation check failed', ['error' => $e->getMessage()]);
            }

            // Count issues by type
            $criticalCount = count(array_filter($allIssues, fn($i) => ($i['type'] ?? '') === 'critical'));
            $warningCount = count(array_filter($allIssues, fn($i) => ($i['type'] ?? '') === 'warning'));

            $audit->setIssues($allIssues);
            $audit->setCriticalIssuesCount($criticalCount);
            $audit->setWarningIssuesCount($warningCount);

            // Calculate overall score
            $scores = array_filter([
                $audit->getTechnicalScore(),
                $audit->getSeoScore(),
                $audit->getSecurityScore(),
                $audit->getPerformanceScoreMobile(),
            ]);

            if (!empty($scores)) {
                $audit->setOverallScore((int) round(array_sum($scores) / count($scores)));
            }

            $audit->setDurationMs((int) round((microtime(true) - $startTime) * 1000));
            $audit->markAsCompleted();

        } catch (\Exception $e) {
            $this->logger->error('Audit failed', ['error' => $e->getMessage()]);
            $audit->markAsFailed($e->getMessage());
        }

        $this->entityManager->flush();

        return $audit;
    }

    /**
     * Run audit asynchronously (for message queue)
     */
    public function runAuditAsync(string $auditId): void
    {
        $audit = $this->entityManager->getRepository(SiteAudit::class)->find($auditId);

        if (!$audit) {
            throw new \RuntimeException("Audit not found: {$auditId}");
        }

        $this->runAudit($audit);
    }

    /**
     * Get audit comparison between two URLs
     */
    public function compareAudits(SiteAudit $audit1, SiteAudit $audit2): array
    {
        return [
            'audit1' => [
                'url' => $audit1->getUrl(),
                'overall_score' => $audit1->getOverallScore(),
                'performance_mobile' => $audit1->getPerformanceScoreMobile(),
                'indexed_pages' => $audit1->getIndexedPages(),
                'critical_issues' => $audit1->getCriticalIssuesCount(),
            ],
            'audit2' => [
                'url' => $audit2->getUrl(),
                'overall_score' => $audit2->getOverallScore(),
                'performance_mobile' => $audit2->getPerformanceScoreMobile(),
                'indexed_pages' => $audit2->getIndexedPages(),
                'critical_issues' => $audit2->getCriticalIssuesCount(),
            ],
            'comparison' => [
                'score_difference' => ($audit1->getOverallScore() ?? 0) - ($audit2->getOverallScore() ?? 0),
                'performance_difference' => ($audit1->getPerformanceScoreMobile() ?? 0) - ($audit2->getPerformanceScoreMobile() ?? 0),
                'indexation_ratio' => ($audit2->getIndexedPages() ?? 0) > 0
                    ? round(($audit1->getIndexedPages() ?? 0) / $audit2->getIndexedPages(), 2)
                    : null,
            ],
        ];
    }

    /**
     * Get latest audit for a project
     */
    public function getLatestAudit(Project $project): ?SiteAudit
    {
        return $this->entityManager->getRepository(SiteAudit::class)->findOneBy(
            ['project' => $project],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Get audit history for a project
     */
    public function getAuditHistory(Project $project, int $limit = 10): array
    {
        return $this->entityManager->getRepository(SiteAudit::class)->findBy(
            ['project' => $project],
            ['createdAt' => 'DESC'],
            $limit
        );
    }
}
