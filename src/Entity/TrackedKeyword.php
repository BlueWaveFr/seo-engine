<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'tracked_keywords')]
#[ORM\UniqueConstraint(name: 'unique_project_keyword', columns: ['project_id', 'keyword'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['tracked:read', 'tracked:details']]),
        new GetCollection(normalizationContext: ['groups' => ['tracked:read']]),
        new Post(denormalizationContext: ['groups' => ['tracked:write']]),
        new Patch(denormalizationContext: ['groups' => ['tracked:write']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['tracked:read']],
    denormalizationContext: ['groups' => ['tracked:write']],
    order: ['createdAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'keyword' => 'partial', 'group' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['keyword', 'currentPosition', 'searchVolume', 'createdAt'])]
class TrackedKeyword
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['tracked:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['tracked:read', 'tracked:write'])]
    private Project $project;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['tracked:read', 'tracked:write'])]
    private string $keyword;

    #[ORM\Column(name: 'keyword_group', length: 100, nullable: true)]
    #[Groups(['tracked:read', 'tracked:write'])]
    private ?string $group = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?int $currentPosition = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?int $previousPosition = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?int $bestPosition = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?int $worstPosition = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['tracked:read'])]
    private ?string $rankingUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['tracked:read', 'tracked:write'])]
    private ?string $targetUrl = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?int $searchVolume = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?float $cpc = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?float $competition = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['tracked:read'])]
    private ?string $difficulty = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['tracked:details'])]
    private ?array $serpFeatures = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['tracked:details'])]
    private ?array $positionHistory = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['tracked:read', 'tracked:write'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['tracked:read'])]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['tracked:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['tracked:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): self
    {
        $this->keyword = $keyword;
        return $this;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function setGroup(?string $group): self
    {
        $this->group = $group;
        return $this;
    }

    public function getCurrentPosition(): ?int
    {
        return $this->currentPosition;
    }

    public function setCurrentPosition(?int $currentPosition): self
    {
        $this->currentPosition = $currentPosition;
        return $this;
    }

    public function getPreviousPosition(): ?int
    {
        return $this->previousPosition;
    }

    public function setPreviousPosition(?int $previousPosition): self
    {
        $this->previousPosition = $previousPosition;
        return $this;
    }

    public function getBestPosition(): ?int
    {
        return $this->bestPosition;
    }

    public function setBestPosition(?int $bestPosition): self
    {
        $this->bestPosition = $bestPosition;
        return $this;
    }

    public function getWorstPosition(): ?int
    {
        return $this->worstPosition;
    }

    public function setWorstPosition(?int $worstPosition): self
    {
        $this->worstPosition = $worstPosition;
        return $this;
    }

    public function getRankingUrl(): ?string
    {
        return $this->rankingUrl;
    }

    public function setRankingUrl(?string $rankingUrl): self
    {
        $this->rankingUrl = $rankingUrl;
        return $this;
    }

    public function getTargetUrl(): ?string
    {
        return $this->targetUrl;
    }

    public function setTargetUrl(?string $targetUrl): self
    {
        $this->targetUrl = $targetUrl;
        return $this;
    }

    public function getSearchVolume(): ?int
    {
        return $this->searchVolume;
    }

    public function setSearchVolume(?int $searchVolume): self
    {
        $this->searchVolume = $searchVolume;
        return $this;
    }

    public function getCpc(): ?float
    {
        return $this->cpc;
    }

    public function setCpc(?float $cpc): self
    {
        $this->cpc = $cpc;
        return $this;
    }

    public function getCompetition(): ?float
    {
        return $this->competition;
    }

    public function setCompetition(?float $competition): self
    {
        $this->competition = $competition;
        return $this;
    }

    public function getDifficulty(): ?string
    {
        return $this->difficulty;
    }

    public function setDifficulty(?string $difficulty): self
    {
        $this->difficulty = $difficulty;
        return $this;
    }

    public function getSerpFeatures(): ?array
    {
        return $this->serpFeatures;
    }

    public function setSerpFeatures(?array $serpFeatures): self
    {
        $this->serpFeatures = $serpFeatures;
        return $this;
    }

    public function getPositionHistory(): ?array
    {
        return $this->positionHistory;
    }

    public function setPositionHistory(?array $positionHistory): self
    {
        $this->positionHistory = $positionHistory;
        return $this;
    }

    public function addPositionToHistory(int $position, \DateTimeImmutable $date): self
    {
        $history = $this->positionHistory ?? [];
        $history[] = [
            'position' => $position,
            'date' => $date->format('Y-m-d'),
        ];
        // Keep last 90 days
        if (count($history) > 90) {
            $history = array_slice($history, -90);
        }
        $this->positionHistory = $history;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): self
    {
        $this->lastCheckedAt = $lastCheckedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPositionChange(): ?int
    {
        if ($this->currentPosition === null || $this->previousPosition === null) {
            return null;
        }
        return $this->previousPosition - $this->currentPosition;
    }
}
