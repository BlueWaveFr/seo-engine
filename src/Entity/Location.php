<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[ORM\Entity]
#[ORM\Table(name: 'locations')]
#[ORM\Index(columns: ['project_id', 'type'], name: 'idx_locations_project_type')]
#[ORM\Index(columns: ['dataforseo_location_code'], name: 'idx_locations_dataforseo')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    normalizationContext: ['groups' => ['location:read']],
    denormalizationContext: ['groups' => ['location:write']],
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Delete(),
    ]
)]
class Location
{
    public const TYPE_CITY = 'city';
    public const TYPE_DEPARTMENT = 'department';
    public const TYPE_REGION = 'region';
    public const TYPE_COUNTRY = 'country';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['location:read', 'project:details', 'content:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['location:read', 'location:write', 'project:details', 'content:read'])]
    private string $name;

    #[ORM\Column(length: 255)]
    #[Groups(['location:read', 'project:details'])]
    private string $slug;

    #[ORM\Column(length: 20)]
    #[Groups(['location:read', 'location:write', 'project:details', 'content:read'])]
    private string $type = self::TYPE_CITY;

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['location:read', 'location:write', 'project:details'])]
    private ?string $postalCode = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['location:read', 'location:write', 'project:details'])]
    private ?string $departmentCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['location:read', 'location:write', 'project:details'])]
    private ?string $regionCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['location:read', 'project:details'])]
    private ?string $dataforseoLocationCode = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?array $coordinates = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['location:read', 'project:details'])]
    private ?int $population = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['location:read'])]
    private ?array $localContext = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['location:read'])]
    private ?array $localCompetitors = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['location:read'])]
    private ?array $localKeywords = null;

    #[ORM\ManyToOne(inversedBy: 'locations')]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: GeoZone::class, inversedBy: 'locations')]
    #[Groups(['location:read'])]
    private ?GeoZone $geoZone = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[Groups(['location:read'])]
    private ?Location $parentLocation = null;

    #[ORM\OneToMany(mappedBy: 'location', targetEntity: Content::class)]
    private Collection $contents;

    #[ORM\Column]
    #[Groups(['location:read', 'location:write', 'project:details'])]
    private bool $isPrimary = false;

    #[ORM\Column]
    #[Groups(['location:read', 'location:write', 'project:details'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['location:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['location:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->contents = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->generateSlug();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function generateSlug(): void
    {
        $slugger = new AsciiSlugger('fr');
        $this->slug = strtolower($slugger->slug($this->name)->toString());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->generateSlug();
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
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

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getDepartmentCode(): ?string
    {
        return $this->departmentCode;
    }

    public function setDepartmentCode(?string $departmentCode): static
    {
        $this->departmentCode = $departmentCode;
        return $this;
    }

    public function getRegionCode(): ?string
    {
        return $this->regionCode;
    }

    public function setRegionCode(?string $regionCode): static
    {
        $this->regionCode = $regionCode;
        return $this;
    }

    public function getDataforseoLocationCode(): ?string
    {
        return $this->dataforseoLocationCode;
    }

    public function setDataforseoLocationCode(?string $dataforseoLocationCode): static
    {
        $this->dataforseoLocationCode = $dataforseoLocationCode;
        return $this;
    }

    public function getCoordinates(): ?array
    {
        return $this->coordinates;
    }

    public function setCoordinates(?array $coordinates): static
    {
        $this->coordinates = $coordinates;
        return $this;
    }

    public function getPopulation(): ?int
    {
        return $this->population;
    }

    public function setPopulation(?int $population): static
    {
        $this->population = $population;
        return $this;
    }

    public function getLocalContext(): ?array
    {
        return $this->localContext;
    }

    public function setLocalContext(?array $localContext): static
    {
        $this->localContext = $localContext;
        return $this;
    }

    public function getLocalCompetitors(): ?array
    {
        return $this->localCompetitors;
    }

    public function setLocalCompetitors(?array $localCompetitors): static
    {
        $this->localCompetitors = $localCompetitors;
        return $this;
    }

    public function getLocalKeywords(): ?array
    {
        return $this->localKeywords;
    }

    public function setLocalKeywords(?array $localKeywords): static
    {
        $this->localKeywords = $localKeywords;
        return $this;
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

    public function getGeoZone(): ?GeoZone
    {
        return $this->geoZone;
    }

    public function setGeoZone(?GeoZone $geoZone): static
    {
        $this->geoZone = $geoZone;
        return $this;
    }

    public function getParentLocation(): ?Location
    {
        return $this->parentLocation;
    }

    public function setParentLocation(?Location $parentLocation): static
    {
        $this->parentLocation = $parentLocation;
        return $this;
    }

    /**
     * @return Collection<int, Content>
     */
    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    /**
     * Get display name with type context
     */
    public function getDisplayName(): string
    {
        return match($this->type) {
            self::TYPE_DEPARTMENT => sprintf('%s (%s)', $this->name, $this->departmentCode ?? ''),
            self::TYPE_REGION => $this->name,
            default => $this->postalCode
                ? sprintf('%s (%s)', $this->name, $this->postalCode)
                : $this->name,
        };
    }
}
