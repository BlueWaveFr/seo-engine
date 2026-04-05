<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
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
#[ORM\Table(name: 'contents')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['content:read', 'content:details']]),
        new GetCollection(normalizationContext: ['groups' => ['content:read']]),
        new Post(denormalizationContext: ['groups' => ['content:write']]),
        new Put(denormalizationContext: ['groups' => ['content:write']]),
        new Patch(denormalizationContext: ['groups' => ['content:write']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['content:read']],
    denormalizationContext: ['groups' => ['content:write']],
    paginationItemsPerPage: 50,
    paginationClientItemsPerPage: true
)]
#[ApiFilter(SearchFilter::class, properties: ['project' => 'exact', 'type' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'scheduledAt'])]
class Content
{
    public const TYPE_ARTICLE = 'article';
    public const TYPE_BLOG_POST = 'blog_post';
    public const TYPE_PAGE = 'page';
    public const TYPE_SOCIAL_POST = 'social_post';
    public const TYPE_NEWSLETTER = 'newsletter';
    public const TYPE_LANDING_PAGE = 'landing_page';
    public const TYPE_PRODUCT_DESCRIPTION = 'product_description';
    public const TYPE_COMPREHENSIVE_GUIDE = 'comprehensive_guide';
    public const TYPE_LOCAL_LANDING = 'local_landing';
    public const TYPE_LOCAL_SERVICE = 'local_service';
    public const TYPE_LOCAL_GUIDE = 'local_guide';

    public const STATUS_IDEA = 'idea';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['content:read', 'project:details'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['content:read', 'content:write', 'project:details'])]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?string $body = null;

    #[ORM\Column(length: 30)]
    #[Groups(['content:read', 'content:write', 'project:details'])]
    private string $type = self::TYPE_ARTICLE;

    #[ORM\Column(length: 20)]
    #[Groups(['content:read', 'content:write', 'project:details'])]
    private string $status = self::STATUS_IDEA;

    #[ORM\Column(type: 'json')]
    #[Groups(['content:read', 'content:write'])]
    private array $keywords = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['content:read'])]
    private ?array $aiMetadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?string $targetKeyword = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?string $metaDescription = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?int $estimatedWordCount = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['content:read', 'content:write'])]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['content:read'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(inversedBy: 'contents')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['content:read', 'content:write'])]
    private Project $project;

    #[ORM\ManyToOne(inversedBy: 'contents')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['content:read'])]
    private User $createdBy;

    // Local SEO properties
    #[ORM\ManyToOne(targetEntity: Location::class, inversedBy: 'contents')]
    #[Groups(['content:read', 'content:write'])]
    private ?Location $location = null;

    #[ORM\ManyToOne(targetEntity: GeoZone::class, inversedBy: 'contents')]
    #[Groups(['content:read', 'content:write'])]
    private ?GeoZone $geoZone = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['content:read'])]
    private ?array $localSchemaMarkup = null;

    #[ORM\Column]
    #[Groups(['content:read', 'content:write'])]
    private bool $isLocationSpecific = false;

    #[ORM\Column]
    #[Groups(['content:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['content:read'])]
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
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

    public function getKeywords(): array
    {
        return $this->keywords;
    }

    public function setKeywords(array $keywords): static
    {
        $this->keywords = $keywords;
        return $this;
    }

    public function getAiMetadata(): ?array
    {
        return $this->aiMetadata;
    }

    public function setAiMetadata(?array $aiMetadata): static
    {
        $this->aiMetadata = $aiMetadata;
        return $this;
    }

    public function getTargetKeyword(): ?string
    {
        return $this->targetKeyword;
    }

    public function setTargetKeyword(?string $targetKeyword): static
    {
        $this->targetKeyword = $targetKeyword;
        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;
        return $this;
    }

    public function getEstimatedWordCount(): ?int
    {
        return $this->estimatedWordCount;
    }

    public function setEstimatedWordCount(?int $estimatedWordCount): static
    {
        $this->estimatedWordCount = $estimatedWordCount;
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;
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

    public function publish(): static
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = new \DateTimeImmutable();
        return $this;
    }

    // Local SEO getters/setters
    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;
        if ($location !== null) {
            $this->isLocationSpecific = true;
        }
        return $this;
    }

    public function getGeoZone(): ?GeoZone
    {
        return $this->geoZone;
    }

    public function setGeoZone(?GeoZone $geoZone): static
    {
        $this->geoZone = $geoZone;
        return $this;
    }

    public function getLocalSchemaMarkup(): ?array
    {
        return $this->localSchemaMarkup;
    }

    public function setLocalSchemaMarkup(?array $localSchemaMarkup): static
    {
        $this->localSchemaMarkup = $localSchemaMarkup;
        return $this;
    }

    public function isLocationSpecific(): bool
    {
        return $this->isLocationSpecific;
    }

    public function setIsLocationSpecific(bool $isLocationSpecific): static
    {
        $this->isLocationSpecific = $isLocationSpecific;
        return $this;
    }
}
