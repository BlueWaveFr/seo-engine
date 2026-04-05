<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'companies')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['company:read', 'company:details']]),
        new GetCollection(normalizationContext: ['groups' => ['company:read']]),
        new Post(denormalizationContext: ['groups' => ['company:write']]),
        new Put(denormalizationContext: ['groups' => ['company:write']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['company:read']],
    denormalizationContext: ['groups' => ['company:write']]
)]
class Company
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['company:read', 'user:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['company:read', 'company:write', 'user:read'])]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $website = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $industry = null;

    #[ORM\Column(length: 2)]
    #[Assert\Country]
    #[Groups(['company:read', 'company:write'])]
    private string $country = 'FR';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ahrefsApiKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dataForSeoLogin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dataForSeoPassword = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $googleTokens = null;

    #[ORM\Column(length: 20)]
    #[Groups(['company:read', 'company:write'])]
    private string $accountType = 'trial'; // trial, standard, premium

    #[ORM\Column(nullable: true)]
    #[Groups(['company:read', 'company:write'])]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['company:read'])]
    private bool $isActive = true;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['company:read'])]
    private ?User $owner = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: User::class)]
    #[Groups(['company:details'])]
    private Collection $users;

    #[ORM\OneToOne(mappedBy: 'company', targetEntity: Subscription::class, cascade: ['persist', 'remove'])]
    #[Groups(['company:read'])]
    private ?Subscription $subscription = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Project::class, cascade: ['persist', 'remove'])]
    #[Groups(['company:details'])]
    private Collection $projects;

    #[ORM\Column]
    #[Groups(['company:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['company:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->users = new ArrayCollection();
        $this->projects = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;
        return $this;
    }

    public function getIndustry(): ?string
    {
        return $this->industry;
    }

    public function setIndustry(?string $industry): static
    {
        $this->industry = $industry;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setCompany($this);
        }
        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            if ($user->getCompany() === $this) {
                $user->setCompany(null);
            }
        }
        return $this;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): static
    {
        if ($subscription !== null && $subscription->getCompany() !== $this) {
            $subscription->setCompany($this);
        }
        $this->subscription = $subscription;
        return $this;
    }

    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): static
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->setCompany($this);
        }
        return $this;
    }

    public function removeProject(Project $project): static
    {
        if ($this->projects->removeElement($project)) {
            if ($project->getCompany() === $this) {
                $project->setCompany(null);
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

    public function getAhrefsApiKey(): ?string
    {
        return $this->ahrefsApiKey;
    }

    public function setAhrefsApiKey(?string $ahrefsApiKey): static
    {
        $this->ahrefsApiKey = $ahrefsApiKey;
        return $this;
    }

    public function hasAhrefsApiKey(): bool
    {
        return !empty($this->ahrefsApiKey);
    }

    public function getGoogleTokens(): ?array
    {
        return $this->googleTokens;
    }

    public function setGoogleTokens(?array $googleTokens): static
    {
        $this->googleTokens = $googleTokens;
        return $this;
    }

    public function hasGoogleTokens(): bool
    {
        return !empty($this->googleTokens) && isset($this->googleTokens['access_token']);
    }

    public function getDataForSeoLogin(): ?string
    {
        return $this->dataForSeoLogin;
    }

    public function setDataForSeoLogin(?string $dataForSeoLogin): static
    {
        $this->dataForSeoLogin = $dataForSeoLogin;
        return $this;
    }

    public function getDataForSeoPassword(): ?string
    {
        return $this->dataForSeoPassword;
    }

    public function setDataForSeoPassword(?string $dataForSeoPassword): static
    {
        $this->dataForSeoPassword = $dataForSeoPassword;
        return $this;
    }

    public function hasDataForSeoCredentials(): bool
    {
        return !empty($this->dataForSeoLogin) && !empty($this->dataForSeoPassword);
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): static
    {
        $this->accountType = $accountType;
        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;
        return $this;
    }

    public function isTrialExpired(): bool
    {
        if ($this->accountType !== 'trial') {
            return false;
        }
        if ($this->trialEndsAt === null) {
            return false;
        }
        return $this->trialEndsAt < new \DateTimeImmutable();
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

    public function getDaysLeftInTrial(): ?int
    {
        if ($this->accountType !== 'trial' || $this->trialEndsAt === null) {
            return null;
        }
        $now = new \DateTimeImmutable();
        if ($this->trialEndsAt < $now) {
            return 0;
        }
        return $now->diff($this->trialEndsAt)->days;
    }
}
