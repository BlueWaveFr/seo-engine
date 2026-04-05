<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'site_audits')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['audit:read', 'audit:details']]),
        new GetCollection(normalizationContext: ['groups' => ['audit:read']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['audit:read']],
    order: ['createdAt' => 'DESC'],
    paginationItemsPerPage: 20
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class SiteAudit
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['audit:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['audit:read'])]
    private Project $project;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    #[Groups(['audit:read'])]
    private string $url;

    #[ORM\Column(length: 20)]
    #[Groups(['audit:read'])]
    private string $status = self::STATUS_PENDING;

    // Overall scores
    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $overallScore = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $technicalScore = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $seoScore = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $securityScore = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $performanceScoreMobile = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $performanceScoreDesktop = null;

    // Core Web Vitals (mobile)
    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read', 'audit:details'])]
    private ?float $lcpMobile = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read', 'audit:details'])]
    private ?float $fcpMobile = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read', 'audit:details'])]
    private ?float $clsMobile = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read', 'audit:details'])]
    private ?float $tbtMobile = null;

    // Indexation
    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $indexedPages = null;

    // Safety
    #[ORM\Column]
    #[Groups(['audit:read'])]
    private bool $isSafe = true;

    // Full audit data (JSON)
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['audit:details'])]
    private ?array $crawlerData = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['audit:details'])]
    private ?array $pageSpeedData = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['audit:details'])]
    private ?array $safeBrowsingData = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['audit:details'])]
    private ?array $indexationData = null;

    // Issues summary
    #[ORM\Column(type: 'json')]
    #[Groups(['audit:read'])]
    private array $issues = [];

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $criticalIssuesCount = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $warningIssuesCount = null;

    // Execution info
    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?int $durationMs = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $errorMessage = null;

    #[ORM\Column]
    #[Groups(['audit:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['audit:read'])]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getOverallScore(): ?int
    {
        return $this->overallScore;
    }

    public function setOverallScore(?int $overallScore): static
    {
        $this->overallScore = $overallScore;
        return $this;
    }

    public function getTechnicalScore(): ?int
    {
        return $this->technicalScore;
    }

    public function setTechnicalScore(?int $technicalScore): static
    {
        $this->technicalScore = $technicalScore;
        return $this;
    }

    public function getSeoScore(): ?int
    {
        return $this->seoScore;
    }

    public function setSeoScore(?int $seoScore): static
    {
        $this->seoScore = $seoScore;
        return $this;
    }

    public function getSecurityScore(): ?int
    {
        return $this->securityScore;
    }

    public function setSecurityScore(?int $securityScore): static
    {
        $this->securityScore = $securityScore;
        return $this;
    }

    public function getPerformanceScoreMobile(): ?int
    {
        return $this->performanceScoreMobile;
    }

    public function setPerformanceScoreMobile(?int $score): static
    {
        $this->performanceScoreMobile = $score;
        return $this;
    }

    public function getPerformanceScoreDesktop(): ?int
    {
        return $this->performanceScoreDesktop;
    }

    public function setPerformanceScoreDesktop(?int $score): static
    {
        $this->performanceScoreDesktop = $score;
        return $this;
    }

    public function getLcpMobile(): ?float
    {
        return $this->lcpMobile;
    }

    public function setLcpMobile(?float $lcp): static
    {
        $this->lcpMobile = $lcp;
        return $this;
    }

    public function getFcpMobile(): ?float
    {
        return $this->fcpMobile;
    }

    public function setFcpMobile(?float $fcp): static
    {
        $this->fcpMobile = $fcp;
        return $this;
    }

    public function getClsMobile(): ?float
    {
        return $this->clsMobile;
    }

    public function setClsMobile(?float $cls): static
    {
        $this->clsMobile = $cls;
        return $this;
    }

    public function getTbtMobile(): ?float
    {
        return $this->tbtMobile;
    }

    public function setTbtMobile(?float $tbt): static
    {
        $this->tbtMobile = $tbt;
        return $this;
    }

    public function getIndexedPages(): ?int
    {
        return $this->indexedPages;
    }

    public function setIndexedPages(?int $count): static
    {
        $this->indexedPages = $count;
        return $this;
    }

    public function isSafe(): bool
    {
        return $this->isSafe;
    }

    public function setIsSafe(bool $safe): static
    {
        $this->isSafe = $safe;
        return $this;
    }

    public function getCrawlerData(): ?array
    {
        return $this->crawlerData;
    }

    public function setCrawlerData(?array $data): static
    {
        $this->crawlerData = $data;
        return $this;
    }

    public function getPageSpeedData(): ?array
    {
        return $this->pageSpeedData;
    }

    public function setPageSpeedData(?array $data): static
    {
        $this->pageSpeedData = $data;
        return $this;
    }

    public function getSafeBrowsingData(): ?array
    {
        return $this->safeBrowsingData;
    }

    public function setSafeBrowsingData(?array $data): static
    {
        $this->safeBrowsingData = $data;
        return $this;
    }

    public function getIndexationData(): ?array
    {
        return $this->indexationData;
    }

    public function setIndexationData(?array $data): static
    {
        $this->indexationData = $data;
        return $this;
    }

    public function getIssues(): array
    {
        return $this->issues;
    }

    public function setIssues(array $issues): static
    {
        $this->issues = $issues;
        return $this;
    }

    public function getCriticalIssuesCount(): ?int
    {
        return $this->criticalIssuesCount;
    }

    public function setCriticalIssuesCount(?int $count): static
    {
        $this->criticalIssuesCount = $count;
        return $this;
    }

    public function getWarningIssuesCount(): ?int
    {
        return $this->warningIssuesCount;
    }

    public function setWarningIssuesCount(?int $count): static
    {
        $this->warningIssuesCount = $count;
        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $ms): static
    {
        $this->durationMs = $ms;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $message): static
    {
        $this->errorMessage = $message;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function markAsRunning(): static
    {
        $this->status = self::STATUS_RUNNING;
        return $this;
    }

    public function markAsCompleted(): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsFailed(string $error): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $error;
        $this->completedAt = new \DateTimeImmutable();
        return $this;
    }
}
