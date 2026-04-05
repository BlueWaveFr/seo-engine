<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\User;
use SeoExpert\Engine\Service\DataForSeo\DataForSeoService;
use SeoExpert\Engine\Service\ThirdParty\MozService;
use SeoExpert\Engine\Service\ThirdParty\MajesticService;
use Psr\Log\LoggerInterface;

class UnifiedBacklinksService
{
    public function __construct(
        private readonly DataForSeoService $dataForSeoService,
        private readonly MozService $mozService,
        private readonly MajesticService $majesticService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get unified backlinks analysis from all available sources.
     * Each provider is called independently — partial results are returned on failure.
     */
    public function getUnifiedAnalysis(string $target, User $user): array
    {
        $target = $this->normalizeDomain($target);
        $errors = [];
        $providers = ['dataforseo' => false, 'moz' => false, 'majestic' => false];

        // 1. DataForSeo — primary source for backlinks data
        $summary = null;
        $topBacklinks = [];
        $topDomains = [];
        $topAnchors = [];

        $company = $user->getCompany();
        if ($company) {
            $this->dataForSeoService->setCredentialsFromCompany($company);
        }
        $this->dataForSeoService->setUser($user);

        if ($this->dataForSeoService->isConfigured()) {
            try {
                $summary = $this->dataForSeoService->getBacklinksSummary($target);
                $providers['dataforseo'] = true;
            } catch (\Exception $e) {
                $errors['dataforseo_summary'] = $e->getMessage();
                $this->logger->warning('DataForSeo backlinks summary failed', ['error' => $e->getMessage()]);
            }

            try {
                $blResult = $this->dataForSeoService->getBacklinks($target, 20, 0, 'one_per_domain');
                $topBacklinks = $blResult['backlinks'] ?? [];
            } catch (\Exception $e) {
                $errors['dataforseo_backlinks'] = $e->getMessage();
            }

            try {
                $rdResult = $this->dataForSeoService->getReferringDomains($target, 20);
                $topDomains = $rdResult['referring_domains'] ?? [];
            } catch (\Exception $e) {
                $errors['dataforseo_domains'] = $e->getMessage();
            }

            try {
                $anchorResult = $this->dataForSeoService->getAnchors($target, 20);
                $topAnchors = $anchorResult['anchors'] ?? [];
            } catch (\Exception $e) {
                $errors['dataforseo_anchors'] = $e->getMessage();
            }
        }

        // 2. Moz — authority metrics
        $mozData = $this->getMozData($target);
        if ($mozData !== null) {
            $providers['moz'] = true;
        }

        // 3. Majestic — trust/citation flow
        $majesticData = $this->getMajesticData($target);
        if ($majesticData !== null) {
            $providers['majestic'] = true;
        }

        // Merge authority metrics
        $authorityMetrics = $this->mergeAuthorityMetrics($mozData, $majesticData);

        return [
            'target' => $target,
            'summary' => $summary,
            'authority_metrics' => $authorityMetrics,
            'top_backlinks' => $topBacklinks,
            'top_referring_domains' => $topDomains,
            'top_anchors' => $topAnchors,
            'providers' => $providers,
            'errors' => $errors,
        ];
    }

    private function getMozData(string $domain): ?array
    {
        if (!$this->mozService->isConfigured()) {
            return null;
        }

        try {
            return $this->mozService->getDomainOverview($domain);
        } catch (\Exception $e) {
            $this->logger->warning('Moz data fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getMajesticData(string $domain): ?array
    {
        if (!$this->majesticService->isConfigured()) {
            return null;
        }

        try {
            return $this->majesticService->getBacklinkData($domain);
        } catch (\Exception $e) {
            $this->logger->warning('Majestic data fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function mergeAuthorityMetrics(?array $mozData, ?array $majesticData): array
    {
        $metrics = [
            'domain_authority' => null,
            'page_authority' => null,
            'spam_score' => null,
            'trust_flow' => null,
            'citation_flow' => null,
            'moz_linking_domains' => null,
            'majestic_ref_domains' => null,
            'sources' => [],
        ];

        if ($mozData !== null) {
            $metrics['domain_authority'] = $mozData['metrics']['domain_authority'] ?? $mozData['domain_authority'] ?? null;
            $metrics['page_authority'] = $mozData['metrics']['page_authority'] ?? $mozData['page_authority'] ?? null;
            $metrics['spam_score'] = $mozData['metrics']['spam_score'] ?? $mozData['spam_score'] ?? null;
            $metrics['moz_linking_domains'] = $mozData['metrics']['linking_root_domains'] ?? null;
            $metrics['sources'][] = 'moz';
        }

        if ($majesticData !== null) {
            $metrics['trust_flow'] = $majesticData['TrustFlow'] ?? $majesticData['trust_flow'] ?? null;
            $metrics['citation_flow'] = $majesticData['CitationFlow'] ?? $majesticData['citation_flow'] ?? null;
            $metrics['majestic_ref_domains'] = $majesticData['RefDomains'] ?? $majesticData['ref_domains'] ?? null;
            $metrics['sources'][] = 'majestic';
        }

        return $metrics;
    }

    private function normalizeDomain(string $target): string
    {
        $target = preg_replace('#^https?://#', '', $target);
        $target = preg_replace('#^www\.#', '', $target);
        return rtrim($target, '/');
    }
}
