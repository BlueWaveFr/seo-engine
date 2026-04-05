<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'plans')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['plan:read', 'plan:details']]),
        new GetCollection(normalizationContext: ['groups' => ['plan:read']]),
    ],
    normalizationContext: ['groups' => ['plan:read']]
)]
class Plan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['plan:read'])]
    private Uuid $id;

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['plan:read'])]
    private string $slug;

    #[ORM\Column(length: 100)]
    #[Groups(['plan:read'])]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['plan:read'])]
    private ?string $nameEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['plan:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['plan:read'])]
    private ?string $descriptionEn = null;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $priceMonthly = 0;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $priceYearly = 0;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $monthlyRequests = 100;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $maxProjects = 1;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $maxUsers = 1;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private bool $isPopular = false;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $monthlyAudits = -1;

    #[ORM\Column]
    #[Groups(['plan:read'])]
    private int $position = 0;

    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: PlanFeature::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['plan:read', 'plan:details'])]
    private Collection $features;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->features = new ArrayCollection();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function setNameEn(?string $nameEn): static
    {
        $this->nameEn = $nameEn;
        return $this;
    }

    public function getDescriptionEn(): ?string
    {
        return $this->descriptionEn;
    }

    public function setDescriptionEn(?string $descriptionEn): static
    {
        $this->descriptionEn = $descriptionEn;
        return $this;
    }

    public function getPriceMonthly(): int
    {
        return $this->priceMonthly;
    }

    public function setPriceMonthly(int $priceMonthly): static
    {
        $this->priceMonthly = $priceMonthly;
        return $this;
    }

    public function getPriceYearly(): int
    {
        return $this->priceYearly;
    }

    public function setPriceYearly(int $priceYearly): static
    {
        $this->priceYearly = $priceYearly;
        return $this;
    }

    public function getMonthlyRequests(): int
    {
        return $this->monthlyRequests;
    }

    public function setMonthlyRequests(int $monthlyRequests): static
    {
        $this->monthlyRequests = $monthlyRequests;
        return $this;
    }

    public function getMaxProjects(): int
    {
        return $this->maxProjects;
    }

    public function setMaxProjects(int $maxProjects): static
    {
        $this->maxProjects = $maxProjects;
        return $this;
    }

    public function getMaxUsers(): int
    {
        return $this->maxUsers;
    }

    public function setMaxUsers(int $maxUsers): static
    {
        $this->maxUsers = $maxUsers;
        return $this;
    }

    public function isPopular(): bool
    {
        return $this->isPopular;
    }

    public function getIsPopular(): bool
    {
        return $this->isPopular;
    }

    public function setIsPopular(bool $isPopular): static
    {
        $this->isPopular = $isPopular;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getMonthlyAudits(): int
    {
        return $this->monthlyAudits;
    }

    public function setMonthlyAudits(int $monthlyAudits): static
    {
        $this->monthlyAudits = $monthlyAudits;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * @return Collection<int, PlanFeature>
     */
    public function getFeatures(): Collection
    {
        return $this->features;
    }

    public function addFeature(PlanFeature $feature): static
    {
        if (!$this->features->contains($feature)) {
            $this->features->add($feature);
            $feature->setPlan($this);
        }
        return $this;
    }

    public function removeFeature(PlanFeature $feature): static
    {
        if ($this->features->removeElement($feature)) {
            if ($feature->getPlan() === $this) {
                $feature->setPlan(null);
            }
        }
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
}
