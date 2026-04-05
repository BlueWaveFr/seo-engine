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
#[ORM\Table(name: 'keyword_rankings')]
#[ORM\Index(columns: ['project_id', 'keyword'], name: 'idx_project_keyword')]
#[ORM\Index(columns: ['checked_at'], name: 'idx_checked_at')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['ranking:read', 'ranking:details']]),
        new GetCollection(normalizationContext: ['groups' => ['ranking:read']]),
        new Post(denormalizationContext: ['groups' => ['ranking:write']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['ranking:read']],
    denormalizationContext: ['groups' => ['ranking:write']],
    order: ['checkedAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'keyword' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['checkedAt', 'position', 'keyword'])]
class KeywordRanking
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['ranking:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['ranking:read', 'ranking:write'])]
    private Project $project;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['ranking:read', 'ranking:write'])]
    private string $keyword;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['ranking:read', 'ranking:write'])]
    private ?int $position = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['ranking:read'])]
    private ?int $previousPosition = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['ranking:read'])]
    private ?int $positionChange = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['ranking:read', 'ranking:write'])]
    private ?string $url = null;

    #[ORM\Column(length: 50)]
    #[Groups(['ranking:read', 'ranking:write'])]
    private string $searchEngine = 'google';

    #[ORM\Column(length: 10)]
    #[Groups(['ranking:read', 'ranking:write'])]
    private string $country = 'FR';

    #[ORM\Column(length: 10)]
    #[Groups(['ranking:read', 'ranking:write'])]
    private string $language = 'fr';

    #[ORM\Column(length: 20)]
    #[Groups(['ranking:read', 'ranking:write'])]
    private string $device = 'desktop';

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['ranking:read'])]
    private ?int $searchVolume = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['ranking:read'])]
    private ?float $cpc = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['ranking:read'])]
    private ?float $competition = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['ranking:details'])]
    private ?array $serpFeatures = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['ranking:read'])]
    private \DateTimeImmutable $checkedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['ranking:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->checkedAt = new \DateTimeImmutable();
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

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position;
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

    public function getPositionChange(): ?int
    {
        return $this->positionChange;
    }

    public function setPositionChange(?int $positionChange): self
    {
        $this->positionChange = $positionChange;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getSearchEngine(): string
    {
        return $this->searchEngine;
    }

    public function setSearchEngine(string $searchEngine): self
    {
        $this->searchEngine = $searchEngine;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getDevice(): string
    {
        return $this->device;
    }

    public function setDevice(string $device): self
    {
        $this->device = $device;
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

    public function getSerpFeatures(): ?array
    {
        return $this->serpFeatures;
    }

    public function setSerpFeatures(?array $serpFeatures): self
    {
        $this->serpFeatures = $serpFeatures;
        return $this;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(\DateTimeImmutable $checkedAt): self
    {
        $this->checkedAt = $checkedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
