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
#[ORM\Table(name: 'geo_zones')]
#[ORM\Index(columns: ['project_id'], name: 'idx_geo_zones_project')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    normalizationContext: ['groups' => ['geozone:read']],
    denormalizationContext: ['groups' => ['geozone:write']],
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Delete(),
    ]
)]
class GeoZone
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['geozone:read', 'location:read', 'project:details'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['geozone:read', 'geozone:write', 'location:read', 'project:details'])]
    private string $name;

    #[ORM\Column(length: 255)]
    #[Groups(['geozone:read', 'project:details'])]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['geozone:read', 'geozone:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['geozone:read', 'geozone:write'])]
    private ?array $boundingBox = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['geozone:read'])]
    private ?array $cocoonStrategy = null;

    #[ORM\ManyToOne(inversedBy: 'geoZones')]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\OneToMany(mappedBy: 'geoZone', targetEntity: Location::class)]
    #[Groups(['geozone:read'])]
    private Collection $locations;

    #[ORM\OneToMany(mappedBy: 'geoZone', targetEntity: Content::class)]
    private Collection $contents;

    #[ORM\ManyToOne(targetEntity: Content::class)]
    #[Groups(['geozone:read'])]
    private ?Content $hubContent = null;

    #[ORM\Column]
    #[Groups(['geozone:read', 'geozone:write', 'project:details'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['geozone:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['geozone:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->locations = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getBoundingBox(): ?array
    {
        return $this->boundingBox;
    }

    public function setBoundingBox(?array $boundingBox): static
    {
        $this->boundingBox = $boundingBox;
        return $this;
    }

    public function getCocoonStrategy(): ?array
    {
        return $this->cocoonStrategy;
    }

    public function setCocoonStrategy(?array $cocoonStrategy): static
    {
        $this->cocoonStrategy = $cocoonStrategy;
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

    /**
     * @return Collection<int, Location>
     */
    public function getLocations(): Collection
    {
        return $this->locations;
    }

    public function addLocation(Location $location): static
    {
        if (!$this->locations->contains($location)) {
            $this->locations->add($location);
            $location->setGeoZone($this);
        }
        return $this;
    }

    public function removeLocation(Location $location): static
    {
        if ($this->locations->removeElement($location)) {
            if ($location->getGeoZone() === $this) {
                $location->setGeoZone(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Content>
     */
    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getHubContent(): ?Content
    {
        return $this->hubContent;
    }

    public function setHubContent(?Content $hubContent): static
    {
        $this->hubContent = $hubContent;
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
     * Get the count of locations in this zone
     */
    #[Groups(['geozone:read', 'project:details'])]
    public function getLocationCount(): int
    {
        return $this->locations->count();
    }

    /**
     * Get all active locations in this zone
     */
    public function getActiveLocations(): Collection
    {
        return $this->locations->filter(fn(Location $location) => $location->isActive());
    }
}
