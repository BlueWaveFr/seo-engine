<?php

namespace SeoExpert\Engine\Service\Eeat;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SeoExpert\Engine\Entity\EeatSnapshot;
use SeoExpert\Engine\Entity\Project;

/**
 * Orchestrates E-E-A-T analysis + LLM visibility into trackable snapshots.
 */
class EeatSnapshotService
{
    public function __construct(
        private EeatAnalyzerService $eeatAnalyzer,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Run full E-E-A-T + citability analysis for a project and persist a snapshot.
     */
    public function createSnapshot(
        Project $project,
        ?array $llmData = null,
        ?array $competitorData = null,
    ): EeatSnapshot {
        $url = $project->getWebsiteUrl();
        if (!$url) {
            throw new \InvalidArgumentException('Project has no website URL');
        }

        // Run E-E-A-T analysis
        $this->logger->info('EEAT: Starting analysis for {url}', ['url' => $url]);
        $analysis = $this->eeatAnalyzer->analyze($url);

        // Build snapshot
        $snapshot = new EeatSnapshot();
        $snapshot->setProject($project);
        $snapshot->setUrl($url);

        // Scores
        $snapshot->setEeatScore($analysis['eeat_score']);
        $snapshot->setExperienceScore($analysis['experience_score']);
        $snapshot->setExpertiseScore($analysis['expertise_score']);
        $snapshot->setAuthorityScore($analysis['authority_score']);
        $snapshot->setTrustScore($analysis['trust_score']);
        $snapshot->setAiCitabilityScore($analysis['ai_citability_score']);

        // Details
        $snapshot->setTrustSignals($analysis['trust_signals']);
        $snapshot->setAuthors($analysis['authors']);
        $snapshot->setSchemaData($analysis['schema_data']);
        $snapshot->setSignalDetails($analysis['signal_details']);
        $snapshot->setCitabilityBreakdown($analysis['citability_breakdown']);
        $snapshot->setContentFreshness($analysis['content_freshness']);
        $snapshot->setPagesCrawled($analysis['pages_crawled']);
        $snapshot->setDurationMs($analysis['duration_ms']);

        // Recommendations
        $snapshot->setRecommendations($analysis['recommendations']);

        // LLM visibility data (if provided)
        if ($llmData) {
            $snapshot->setLlmVisibilityScore($llmData['visibility_score'] ?? null);
            $snapshot->setLlmMentionsCount($llmData['mentions_count'] ?? null);
            $snapshot->setLlmDetails($llmData);
        }

        // Competitor comparison (if provided)
        if ($competitorData) {
            $snapshot->setCompetitorComparison($competitorData);
        }

        $this->em->persist($snapshot);
        $this->em->flush();

        $this->logger->info('EEAT: Snapshot created for {project} — score {score}/100, citability {citability}/100', [
            'project' => $project->getName(),
            'score' => $snapshot->getEeatScore(),
            'citability' => $snapshot->getAiCitabilityScore(),
        ]);

        return $snapshot;
    }

    /**
     * Run multi-page E-E-A-T analysis for a project.
     */
    public function createMultiPageSnapshot(
        Project $project,
        array $urls,
        ?array $llmData = null,
    ): EeatSnapshot {
        $mainUrl = $project->getWebsiteUrl();
        if (!$mainUrl) {
            throw new \InvalidArgumentException('Project has no website URL');
        }

        // Ensure main URL is included
        if (!in_array($mainUrl, $urls)) {
            array_unshift($urls, $mainUrl);
        }

        $analysis = $this->eeatAnalyzer->analyzeMultiplePages($urls, $mainUrl);

        $snapshot = new EeatSnapshot();
        $snapshot->setProject($project);
        $snapshot->setUrl($mainUrl);
        $snapshot->setEeatScore($analysis['eeat_score']);
        $snapshot->setExperienceScore($analysis['experience_score']);
        $snapshot->setExpertiseScore($analysis['expertise_score']);
        $snapshot->setAuthorityScore($analysis['authority_score']);
        $snapshot->setTrustScore($analysis['trust_score']);
        $snapshot->setAiCitabilityScore($analysis['ai_citability_score']);
        $snapshot->setTrustSignals($analysis['trust_signals']);
        $snapshot->setAuthors($analysis['authors']);
        $snapshot->setSchemaData($analysis['schema_data'] ?? []);
        $snapshot->setSignalDetails($analysis['signal_details']);
        $snapshot->setCitabilityBreakdown($analysis['citability_breakdown']);
        $snapshot->setContentFreshness($analysis['content_freshness']);
        $snapshot->setPagesCrawled($analysis['pages_crawled']);
        $snapshot->setRecommendations($analysis['recommendations']);

        if ($llmData) {
            $snapshot->setLlmVisibilityScore($llmData['visibility_score'] ?? null);
            $snapshot->setLlmMentionsCount($llmData['mentions_count'] ?? null);
            $snapshot->setLlmDetails($llmData);
        }

        $this->em->persist($snapshot);
        $this->em->flush();

        return $snapshot;
    }

    /**
     * Get snapshot history for a project (for trend graphs).
     */
    public function getHistory(Project $project, int $limit = 30): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(EeatSnapshot::class, 's')
            ->where('s.project = :project')
            ->setParameter('project', $project)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the latest snapshot for a project.
     */
    public function getLatest(Project $project): ?EeatSnapshot
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(EeatSnapshot::class, 's')
            ->where('s.project = :project')
            ->setParameter('project', $project)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compare E-E-A-T between project and a competitor URL.
     */
    public function compareWithCompetitor(Project $project, string $competitorUrl): array
    {
        $projectUrl = $project->getWebsiteUrl();
        if (!$projectUrl) {
            throw new \InvalidArgumentException('Project has no website URL');
        }

        $projectAnalysis = $this->eeatAnalyzer->analyze($projectUrl);
        $competitorAnalysis = $this->eeatAnalyzer->analyze($competitorUrl);

        $comparison = [];
        $pillars = ['eeat_score', 'experience_score', 'expertise_score', 'authority_score', 'trust_score', 'ai_citability_score'];

        foreach ($pillars as $key) {
            $comparison[$key] = [
                'project' => $projectAnalysis[$key],
                'competitor' => $competitorAnalysis[$key],
                'difference' => $projectAnalysis[$key] - $competitorAnalysis[$key],
                'winner' => $projectAnalysis[$key] >= $competitorAnalysis[$key] ? 'project' : 'competitor',
            ];
        }

        // Identify missing signals compared to competitor
        $competitorSignals = $competitorAnalysis['trust_signals'];
        $projectSignals = $projectAnalysis['trust_signals'];
        $missingSignals = array_values(array_diff($competitorSignals, $projectSignals));
        $advantageSignals = array_values(array_diff($projectSignals, $competitorSignals));

        return [
            'project' => [
                'url' => $projectUrl,
                'scores' => array_intersect_key($projectAnalysis, array_flip($pillars)),
                'trust_signals' => $projectSignals,
                'authors_count' => count($projectAnalysis['authors']),
            ],
            'competitor' => [
                'url' => $competitorUrl,
                'scores' => array_intersect_key($competitorAnalysis, array_flip($pillars)),
                'trust_signals' => $competitorSignals,
                'authors_count' => count($competitorAnalysis['authors']),
            ],
            'comparison' => $comparison,
            'missing_signals' => $missingSignals,
            'advantage_signals' => $advantageSignals,
            'recommendations' => $this->generateCompetitorRecommendations(
                $projectAnalysis, $competitorAnalysis, $missingSignals
            ),
        ];
    }

    private function generateCompetitorRecommendations(
        array $project, array $competitor, array $missingSignals
    ): array {
        $recs = [];

        if ($competitor['ai_citability_score'] > $project['ai_citability_score']) {
            $gap = $competitor['ai_citability_score'] - $project['ai_citability_score'];
            $recs[] = [
                'priority' => 'critical',
                'title' => "Votre concurrent a un score de citabilite IA superieur de {$gap} points",
                'description' => 'Concentrez-vous sur les signaux manquants ci-dessous pour combler l\'ecart.',
            ];
        }

        $signalLabels = [
            'llms_txt' => 'Creer un fichier llms.txt (votre concurrent en a un)',
            'editorial_policy' => 'Publier une charte editoriale (votre concurrent en a une)',
            'third_party_reviews' => 'Ajouter des avis tiers (Trustpilot, Google Reviews...)',
            'schema_person' => 'Implementer le Schema.org Person pour vos auteurs',
            'social_profiles' => 'Lier vos profils reseaux sociaux',
            'structured_contact' => 'Ajouter vos coordonnees en Schema.org',
            'publication_date' => 'Afficher les dates de publication sur vos contenus',
        ];

        foreach ($missingSignals as $signal) {
            if (isset($signalLabels[$signal])) {
                $recs[] = [
                    'priority' => 'high',
                    'title' => $signalLabels[$signal],
                    'signal' => $signal,
                ];
            }
        }

        return array_slice($recs, 0, 8);
    }
}
