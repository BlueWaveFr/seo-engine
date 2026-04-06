<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'eeat_snapshots')]
#[ORM\Index(columns: ['project_id', 'created_at'], name: 'idx_eeat_project_date')]
#[ORM\HasLifecycleCallbacks]
class EeatSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['eeat:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['eeat:read'])]
    private Project $project;

    // ─── E-E-A-T Scores (/100) ───────────────────────────────────────────

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $eeatScore = 0;

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $experienceScore = 0;

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $expertiseScore = 0;

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $authorityScore = 0;

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $trustScore = 0;

    // ─── AI Citability Score (/100) ──────────────────────────────────────

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $aiCitabilityScore = 0;

    // ─── LLM Visibility ─────────────────────────────────────────────────

    #[ORM\Column(nullable: true)]
    #[Groups(['eeat:read'])]
    private ?int $llmVisibilityScore = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['eeat:read'])]
    private ?int $llmMentionsCount = null;

    // ─── Detailed Data (JSON) ────────────────────────────────────────────

    /** Trust signals found (array of signal keys) */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:details'])]
    private array $trustSignals = [];

    /** Authors detected on the site */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:details'])]
    private array $authors = [];

    /** Schema.org data found */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:details'])]
    private array $schemaData = [];

    /** Per-signal scoring breakdown */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:details'])]
    private array $signalDetails = [];

    /** AI citability breakdown */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:details'])]
    private array $citabilityBreakdown = [];

    /** LLM mentions details (per-LLM, keywords, competitors) */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['eeat:details'])]
    private ?array $llmDetails = null;

    /** Top competitor comparison */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:details'])]
    private array $competitorComparison = [];

    /** Actionable recommendations sorted by priority */
    #[ORM\Column(type: 'json')]
    #[Groups(['eeat:read'])]
    private array $recommendations = [];

    /** URL analyzed (homepage or specific page) */
    #[ORM\Column(length: 500)]
    #[Groups(['eeat:read'])]
    private string $url;

    /** Number of pages crawled for multi-page analysis */
    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private int $pagesCrawled = 1;

    /** Content freshness assessment */
    #[ORM\Column(length: 20)]
    #[Groups(['eeat:read'])]
    private string $contentFreshness = 'unknown';

    #[ORM\Column(nullable: true)]
    #[Groups(['eeat:read'])]
    private ?int $durationMs = null;

    #[ORM\Column]
    #[Groups(['eeat:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    // ─── Getters / Setters ───────────────────────────────────────────────

    public function getId(): Uuid { return $this->id; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): static { $this->project = $project; return $this; }

    public function getEeatScore(): int { return $this->eeatScore; }
    public function setEeatScore(int $score): static { $this->eeatScore = $score; return $this; }

    public function getExperienceScore(): int { return $this->experienceScore; }
    public function setExperienceScore(int $score): static { $this->experienceScore = $score; return $this; }

    public function getExpertiseScore(): int { return $this->expertiseScore; }
    public function setExpertiseScore(int $score): static { $this->expertiseScore = $score; return $this; }

    public function getAuthorityScore(): int { return $this->authorityScore; }
    public function setAuthorityScore(int $score): static { $this->authorityScore = $score; return $this; }

    public function getTrustScore(): int { return $this->trustScore; }
    public function setTrustScore(int $score): static { $this->trustScore = $score; return $this; }

    public function getAiCitabilityScore(): int { return $this->aiCitabilityScore; }
    public function setAiCitabilityScore(int $score): static { $this->aiCitabilityScore = $score; return $this; }

    public function getLlmVisibilityScore(): ?int { return $this->llmVisibilityScore; }
    public function setLlmVisibilityScore(?int $score): static { $this->llmVisibilityScore = $score; return $this; }

    public function getLlmMentionsCount(): ?int { return $this->llmMentionsCount; }
    public function setLlmMentionsCount(?int $count): static { $this->llmMentionsCount = $count; return $this; }

    public function getTrustSignals(): array { return $this->trustSignals; }
    public function setTrustSignals(array $signals): static { $this->trustSignals = $signals; return $this; }

    public function getAuthors(): array { return $this->authors; }
    public function setAuthors(array $authors): static { $this->authors = $authors; return $this; }

    public function getSchemaData(): array { return $this->schemaData; }
    public function setSchemaData(array $data): static { $this->schemaData = $data; return $this; }

    public function getSignalDetails(): array { return $this->signalDetails; }
    public function setSignalDetails(array $details): static { $this->signalDetails = $details; return $this; }

    public function getCitabilityBreakdown(): array { return $this->citabilityBreakdown; }
    public function setCitabilityBreakdown(array $breakdown): static { $this->citabilityBreakdown = $breakdown; return $this; }

    public function getLlmDetails(): ?array { return $this->llmDetails; }
    public function setLlmDetails(?array $details): static { $this->llmDetails = $details; return $this; }

    public function getCompetitorComparison(): array { return $this->competitorComparison; }
    public function setCompetitorComparison(array $comparison): static { $this->competitorComparison = $comparison; return $this; }

    public function getRecommendations(): array { return $this->recommendations; }
    public function setRecommendations(array $recommendations): static { $this->recommendations = $recommendations; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): static { $this->url = $url; return $this; }

    public function getPagesCrawled(): int { return $this->pagesCrawled; }
    public function setPagesCrawled(int $count): static { $this->pagesCrawled = $count; return $this; }

    public function getContentFreshness(): string { return $this->contentFreshness; }
    public function setContentFreshness(string $freshness): static { $this->contentFreshness = $freshness; return $this; }

    public function getDurationMs(): ?int { return $this->durationMs; }
    public function setDurationMs(?int $ms): static { $this->durationMs = $ms; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    // ─── Computed ────────────────────────────────────────────────────────

    #[Groups(['eeat:read'])]
    public function getEeatLevel(): string
    {
        return match(true) {
            $this->eeatScore >= 80 => 'excellent',
            $this->eeatScore >= 60 => 'good',
            $this->eeatScore >= 40 => 'fair',
            $this->eeatScore >= 20 => 'weak',
            default => 'critical',
        };
    }

    #[Groups(['eeat:read'])]
    public function getCitabilityLevel(): string
    {
        return match(true) {
            $this->aiCitabilityScore >= 80 => 'highly_citable',
            $this->aiCitabilityScore >= 60 => 'citable',
            $this->aiCitabilityScore >= 40 => 'partially_citable',
            $this->aiCitabilityScore >= 20 => 'low_citability',
            default => 'not_citable',
        };
    }
}
