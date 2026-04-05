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
use App\State\ProjectProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'projects')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['project:read', 'project:details']]),
        new GetCollection(normalizationContext: ['groups' => ['project:read']]),
        new Post(
            denormalizationContext: ['groups' => ['project:write']],
            processor: ProjectProcessor::class
        ),
        new Put(denormalizationContext: ['groups' => ['project:write']]),
        new Patch(denormalizationContext: ['groups' => ['project:write']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['project:read']],
    denormalizationContext: ['groups' => ['project:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['company' => 'exact', 'name' => 'partial'])]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['project:read', 'content:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['project:read', 'project:write', 'content:read'])]
    private string $name;

    #[ORM\Column(length: 255)]
    #[Groups(['project:read'])]
    private string $slug = '';

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $siteType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url]
    #[Groups(['project:read', 'project:write'])]
    private ?string $websiteUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $industry = null;

    #[ORM\Column(length: 2)]
    #[Assert\Country]
    #[Groups(['project:read', 'project:write'])]
    private string $targetCountry = 'FR';

    #[ORM\Column(length: 10)]
    #[Groups(['project:read', 'project:write'])]
    private string $targetLanguage = 'fr';

    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $targetAudience = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $keywords = [];

    // Ton et style de communication
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $tone = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $writingStyle = null;

    // Objectifs de conversion
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $conversionGoal = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $callToActions = [];

    // Proposition de valeur unique
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $uniqueValueProposition = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $strengths = [];

    // Personas et clients
    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $painPoints = [];

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $audienceExpertiseLevel = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $buyingCriteria = [];

    // Concurrents
    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $competitors = [];

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $competitivePositioning = null;

    // Contraintes de marque
    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $brandKeywords = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $forbiddenWords = [];

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $brandGuidelines = null;

    // Preferences de contenu
    #[ORM\Column(type: 'json')]
    #[Groups(['project:read', 'project:write'])]
    private array $preferredContentTypes = [];

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $preferredContentLength = null;

    // WordPress integration settings
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $wordpressUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['project:write'])]
    private ?string $wordpressUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['project:write'])]
    private ?string $wordpressAppPassword = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $wordpressDefaultPostType = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $wordpressDefaultStatus = 'draft';

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['project:read'])]
    private ?array $wordpressPostTypes = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['project:read'])]
    private ?\DateTimeImmutable $wordpressConnectedAt = null;

    // Cloudflare integration settings
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cloudflareApiToken = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['project:read'])]
    private ?string $cloudflareZoneId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['project:read'])]
    private ?array $crawledData = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['project:read'])]
    private ?\DateTimeImmutable $lastCrawledAt = null;

    // Local SEO settings
    #[ORM\Column]
    #[Groups(['project:read', 'project:write'])]
    private bool $localSeoEnabled = false;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $localSeoScope = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?array $localServices = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?array $localBusinessInfo = null;

    #[ORM\ManyToOne(inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['project:read'])]
    private Company $company;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['project:read'])]
    private ?User $owner = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'project_shared_users')]
    #[Groups(['project:read'])]
    private Collection $sharedWith;

    #[ORM\ManyToOne(inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['project:read', 'project:write'])]
    private ?Member $member = null;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Content::class, cascade: ['persist', 'remove'])]
    #[Groups(['project:details'])]
    private Collection $contents;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: Location::class, cascade: ['persist', 'remove'])]
    #[Groups(['project:details'])]
    private Collection $locations;

    #[ORM\OneToMany(mappedBy: 'project', targetEntity: GeoZone::class, cascade: ['persist', 'remove'])]
    #[Groups(['project:details'])]
    private Collection $geoZones;

    #[ORM\Column]
    #[Groups(['project:read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['project:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['project:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->contents = new ArrayCollection();
        $this->sharedWith = new ArrayCollection();
        $this->locations = new ArrayCollection();
        $this->geoZones = new ArrayCollection();
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
        $this->updateSlug();
        return $this;
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

    private function updateSlug(): void
    {
        $slug = mb_strtolower($this->name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $this->slug = trim($slug, '-');
    }

    public function getSiteType(): ?string
    {
        return $this->siteType;
    }

    public function setSiteType(?string $siteType): static
    {
        $this->siteType = $siteType;
        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): static
    {
        $this->websiteUrl = $websiteUrl;
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

    public function getIndustry(): ?string
    {
        return $this->industry;
    }

    public function setIndustry(?string $industry): static
    {
        $this->industry = $industry;
        return $this;
    }

    public function getTargetCountry(): string
    {
        return $this->targetCountry;
    }

    public function setTargetCountry(string $targetCountry): static
    {
        $this->targetCountry = $targetCountry;
        return $this;
    }

    public function getTargetLanguage(): string
    {
        return $this->targetLanguage;
    }

    public function setTargetLanguage(string $targetLanguage): static
    {
        $this->targetLanguage = $targetLanguage;
        return $this;
    }

    public function getTargetAudience(): array
    {
        return $this->targetAudience;
    }

    public function setTargetAudience(array $targetAudience): static
    {
        $this->targetAudience = $targetAudience;
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

    public function getCrawledData(): ?array
    {
        return $this->crawledData;
    }

    public function setCrawledData(?array $crawledData): static
    {
        $this->crawledData = $crawledData;
        $this->lastCrawledAt = new \DateTimeImmutable();
        return $this;
    }

    public function getLastCrawledAt(): ?\DateTimeImmutable
    {
        return $this->lastCrawledAt;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;
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

    /**
     * @return Collection<int, User>
     */
    public function getSharedWith(): Collection
    {
        return $this->sharedWith;
    }

    public function addSharedWith(User $user): static
    {
        if (!$this->sharedWith->contains($user)) {
            $this->sharedWith->add($user);
        }
        return $this;
    }

    public function removeSharedWith(User $user): static
    {
        $this->sharedWith->removeElement($user);
        return $this;
    }

    public function isSharedWithUser(User $user): bool
    {
        return $this->sharedWith->contains($user);
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function setMember(?Member $member): static
    {
        $this->member = $member;
        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function addContent(Content $content): static
    {
        if (!$this->contents->contains($content)) {
            $this->contents->add($content);
            $content->setProject($this);
        }
        return $this;
    }

    public function removeContent(Content $content): static
    {
        if ($this->contents->removeElement($content)) {
            if ($content->getProject() === $this) {
                $content->setProject(null);
            }
        }
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

    // Ton et style
    public function getTone(): ?string
    {
        return $this->tone;
    }

    public function setTone(?string $tone): static
    {
        $this->tone = $tone;
        return $this;
    }

    public function getWritingStyle(): ?string
    {
        return $this->writingStyle;
    }

    public function setWritingStyle(?string $writingStyle): static
    {
        $this->writingStyle = $writingStyle;
        return $this;
    }

    // Objectifs de conversion
    public function getConversionGoal(): ?string
    {
        return $this->conversionGoal;
    }

    public function setConversionGoal(?string $conversionGoal): static
    {
        $this->conversionGoal = $conversionGoal;
        return $this;
    }

    public function getCallToActions(): array
    {
        return $this->callToActions;
    }

    public function setCallToActions(array $callToActions): static
    {
        $this->callToActions = $callToActions;
        return $this;
    }

    // Proposition de valeur unique
    public function getUniqueValueProposition(): ?string
    {
        return $this->uniqueValueProposition;
    }

    public function setUniqueValueProposition(?string $uniqueValueProposition): static
    {
        $this->uniqueValueProposition = $uniqueValueProposition;
        return $this;
    }

    public function getStrengths(): array
    {
        return $this->strengths;
    }

    public function setStrengths(array $strengths): static
    {
        $this->strengths = $strengths;
        return $this;
    }

    // Personas et clients
    public function getPainPoints(): array
    {
        return $this->painPoints;
    }

    public function setPainPoints(array $painPoints): static
    {
        $this->painPoints = $painPoints;
        return $this;
    }

    public function getAudienceExpertiseLevel(): ?string
    {
        return $this->audienceExpertiseLevel;
    }

    public function setAudienceExpertiseLevel(?string $audienceExpertiseLevel): static
    {
        $this->audienceExpertiseLevel = $audienceExpertiseLevel;
        return $this;
    }

    public function getBuyingCriteria(): array
    {
        return $this->buyingCriteria;
    }

    public function setBuyingCriteria(array $buyingCriteria): static
    {
        $this->buyingCriteria = $buyingCriteria;
        return $this;
    }

    // Concurrents
    public function getCompetitors(): array
    {
        return $this->competitors;
    }

    public function setCompetitors(array $competitors): static
    {
        $this->competitors = $competitors;
        return $this;
    }

    public function getCompetitivePositioning(): ?string
    {
        return $this->competitivePositioning;
    }

    public function setCompetitivePositioning(?string $competitivePositioning): static
    {
        $this->competitivePositioning = $competitivePositioning;
        return $this;
    }

    // Contraintes de marque
    public function getBrandKeywords(): array
    {
        return $this->brandKeywords;
    }

    public function setBrandKeywords(array $brandKeywords): static
    {
        $this->brandKeywords = $brandKeywords;
        return $this;
    }

    public function getForbiddenWords(): array
    {
        return $this->forbiddenWords;
    }

    public function setForbiddenWords(array $forbiddenWords): static
    {
        $this->forbiddenWords = $forbiddenWords;
        return $this;
    }

    public function getBrandGuidelines(): ?string
    {
        return $this->brandGuidelines;
    }

    public function setBrandGuidelines(?string $brandGuidelines): static
    {
        $this->brandGuidelines = $brandGuidelines;
        return $this;
    }

    // Preferences de contenu
    public function getPreferredContentTypes(): array
    {
        return $this->preferredContentTypes;
    }

    public function setPreferredContentTypes(array $preferredContentTypes): static
    {
        $this->preferredContentTypes = $preferredContentTypes;
        return $this;
    }

    public function getPreferredContentLength(): ?string
    {
        return $this->preferredContentLength;
    }

    public function setPreferredContentLength(?string $preferredContentLength): static
    {
        $this->preferredContentLength = $preferredContentLength;
        return $this;
    }

    // WordPress integration getters/setters
    public function getWordpressUrl(): ?string
    {
        return $this->wordpressUrl;
    }

    public function setWordpressUrl(?string $wordpressUrl): static
    {
        $this->wordpressUrl = $wordpressUrl;
        return $this;
    }

    public function getWordpressUsername(): ?string
    {
        return $this->wordpressUsername;
    }

    public function setWordpressUsername(?string $wordpressUsername): static
    {
        $this->wordpressUsername = $wordpressUsername;
        return $this;
    }

    public function getWordpressAppPassword(): ?string
    {
        return $this->wordpressAppPassword;
    }

    public function setWordpressAppPassword(?string $wordpressAppPassword): static
    {
        $this->wordpressAppPassword = $wordpressAppPassword;
        return $this;
    }

    public function getWordpressDefaultPostType(): ?string
    {
        return $this->wordpressDefaultPostType;
    }

    public function setWordpressDefaultPostType(?string $wordpressDefaultPostType): static
    {
        $this->wordpressDefaultPostType = $wordpressDefaultPostType;
        return $this;
    }

    public function getWordpressDefaultStatus(): ?string
    {
        return $this->wordpressDefaultStatus;
    }

    public function setWordpressDefaultStatus(?string $wordpressDefaultStatus): static
    {
        $this->wordpressDefaultStatus = $wordpressDefaultStatus;
        return $this;
    }

    public function getWordpressPostTypes(): ?array
    {
        return $this->wordpressPostTypes;
    }

    public function setWordpressPostTypes(?array $wordpressPostTypes): static
    {
        $this->wordpressPostTypes = $wordpressPostTypes;
        return $this;
    }

    public function getWordpressConnectedAt(): ?\DateTimeImmutable
    {
        return $this->wordpressConnectedAt;
    }

    public function setWordpressConnectedAt(?\DateTimeImmutable $wordpressConnectedAt): static
    {
        $this->wordpressConnectedAt = $wordpressConnectedAt;
        return $this;
    }

    public function hasWordpressConnection(): bool
    {
        return $this->wordpressUrl !== null
            && $this->wordpressUsername !== null
            && $this->wordpressAppPassword !== null;
    }

    #[Groups(['project:read'])]
    public function isWordpressConnected(): bool
    {
        return $this->wordpressConnectedAt !== null;
    }

    // Local SEO getters/setters
    public function isLocalSeoEnabled(): bool
    {
        return $this->localSeoEnabled;
    }

    public function setLocalSeoEnabled(bool $localSeoEnabled): static
    {
        $this->localSeoEnabled = $localSeoEnabled;
        return $this;
    }

    public function getLocalSeoScope(): ?string
    {
        return $this->localSeoScope;
    }

    public function setLocalSeoScope(?string $localSeoScope): static
    {
        $this->localSeoScope = $localSeoScope;
        return $this;
    }

    public function getLocalServices(): ?array
    {
        return $this->localServices;
    }

    public function setLocalServices(?array $localServices): static
    {
        $this->localServices = $localServices;
        return $this;
    }

    public function getLocalBusinessInfo(): ?array
    {
        return $this->localBusinessInfo;
    }

    public function setLocalBusinessInfo(?array $localBusinessInfo): static
    {
        $this->localBusinessInfo = $localBusinessInfo;
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
            $location->setProject($this);
        }
        return $this;
    }

    public function removeLocation(Location $location): static
    {
        if ($this->locations->removeElement($location)) {
            if ($location->getProject() === $this) {
                $location->setProject(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, GeoZone>
     */
    public function getGeoZones(): Collection
    {
        return $this->geoZones;
    }

    public function addGeoZone(GeoZone $geoZone): static
    {
        if (!$this->geoZones->contains($geoZone)) {
            $this->geoZones->add($geoZone);
            $geoZone->setProject($this);
        }
        return $this;
    }

    public function removeGeoZone(GeoZone $geoZone): static
    {
        if ($this->geoZones->removeElement($geoZone)) {
            if ($geoZone->getProject() === $this) {
                $geoZone->setProject(null);
            }
        }
        return $this;
    }

    /**
     * Get the primary location for this project
     */
    public function getPrimaryLocation(): ?Location
    {
        foreach ($this->locations as $location) {
            if ($location->isPrimary()) {
                return $location;
            }
        }
        return $this->locations->first() ?: null;
    }

    /**
     * Get active locations count
     */
    #[Groups(['project:read'])]
    public function getActiveLocationsCount(): int
    {
        return $this->locations->filter(fn(Location $l) => $l->isActive())->count();
    }

    // Cloudflare integration getters/setters
    public function getCloudflareApiToken(): ?string
    {
        return $this->cloudflareApiToken;
    }

    public function setCloudflareApiToken(?string $cloudflareApiToken): static
    {
        $this->cloudflareApiToken = $cloudflareApiToken;
        return $this;
    }

    public function getCloudflareZoneId(): ?string
    {
        return $this->cloudflareZoneId;
    }

    public function setCloudflareZoneId(?string $cloudflareZoneId): static
    {
        $this->cloudflareZoneId = $cloudflareZoneId;
        return $this;
    }

    public function hasCloudflareConfig(): bool
    {
        return !empty($this->cloudflareApiToken) && !empty($this->cloudflareZoneId);
    }

    #[Groups(['project:read'])]
    public function isCloudflareConnected(): bool
    {
        return $this->hasCloudflareConfig();
    }
}
