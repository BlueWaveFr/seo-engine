<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'keyword_searches')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['company_id', 'created_at'], name: 'idx_keyword_search_company_date')]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['keyword_search:read', 'keyword_search:details']]),
        new GetCollection(normalizationContext: ['groups' => ['keyword_search:read']]),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['keyword_search:read']],
    order: ['createdAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: ['keyword' => 'partial', 'company' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'keyword'])]
class KeywordSearch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['keyword_search:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['keyword_search:read'])]
    private string $keyword;

    #[ORM\Column(length: 10)]
    #[Groups(['keyword_search:read'])]
    private string $locationCode = '2250';

    #[ORM\Column(length: 5)]
    #[Groups(['keyword_search:read'])]
    private string $languageCode = 'fr';

    #[ORM\Column(nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?int $searchVolume = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?float $cpc = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?float $competition = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?string $competitionLevel = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?int $difficulty = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?string $searchIntent = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['keyword_search:details'])]
    private ?array $monthlySearches = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['keyword_search:details'])]
    private ?array $suggestions = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['keyword_search:details'])]
    private ?array $relatedKeywords = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['keyword_search:details'])]
    private ?array $serpResults = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['keyword_search:details'])]
    private ?array $serpFeatures = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['keyword_search:details'])]
    private ?array $peopleAlsoAsk = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['keyword_search:read'])]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['keyword_search:read'])]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['keyword_search:read'])]
    private ?Project $project = null;

    #[ORM\Column]
    #[Groups(['keyword_search:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKeyword(): string
    {
        return $this->keyword;
    }

    public function setKeyword(string $keyword): static
    {
        $this->keyword = $keyword;
        return $this;
    }

    public function getLocationCode(): string
    {
        return $this->locationCode;
    }

    public function setLocationCode(string $locationCode): static
    {
        $this->locationCode = $locationCode;
        return $this;
    }

    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    public function setLanguageCode(string $languageCode): static
    {
        $this->languageCode = $languageCode;
        return $this;
    }

    public function getSearchVolume(): ?int
    {
        return $this->searchVolume;
    }

    public function setSearchVolume(?int $searchVolume): static
    {
        $this->searchVolume = $searchVolume;
        return $this;
    }

    public function getCpc(): ?float
    {
        return $this->cpc;
    }

    public function setCpc(?float $cpc): static
    {
        $this->cpc = $cpc;
        return $this;
    }

    public function getCompetition(): ?float
    {
        return $this->competition;
    }

    public function setCompetition(?float $competition): static
    {
        $this->competition = $competition;
        return $this;
    }

    public function getCompetitionLevel(): ?string
    {
        return $this->competitionLevel;
    }

    public function setCompetitionLevel(?string $competitionLevel): static
    {
        $this->competitionLevel = $competitionLevel;
        return $this;
    }

    public function getDifficulty(): ?int
    {
        return $this->difficulty;
    }

    public function setDifficulty(?int $difficulty): static
    {
        $this->difficulty = $difficulty;
        return $this;
    }

    public function getSearchIntent(): ?string
    {
        return $this->searchIntent;
    }

    public function setSearchIntent(?string $searchIntent): static
    {
        $this->searchIntent = $searchIntent;
        return $this;
    }

    public function getMonthlySearches(): ?array
    {
        return $this->monthlySearches;
    }

    public function setMonthlySearches(?array $monthlySearches): static
    {
        $this->monthlySearches = $monthlySearches;
        return $this;
    }

    public function getSuggestions(): ?array
    {
        return $this->suggestions;
    }

    public function setSuggestions(?array $suggestions): static
    {
        $this->suggestions = $suggestions;
        return $this;
    }

    public function getRelatedKeywords(): ?array
    {
        return $this->relatedKeywords;
    }

    public function setRelatedKeywords(?array $relatedKeywords): static
    {
        $this->relatedKeywords = $relatedKeywords;
        return $this;
    }

    public function getSerpResults(): ?array
    {
        return $this->serpResults;
    }

    public function setSerpResults(?array $serpResults): static
    {
        $this->serpResults = $serpResults;
        return $this;
    }

    public function getSerpFeatures(): ?array
    {
        return $this->serpFeatures;
    }

    public function setSerpFeatures(?array $serpFeatures): static
    {
        $this->serpFeatures = $serpFeatures;
        return $this;
    }

    public function getPeopleAlsoAsk(): ?array
    {
        return $this->peopleAlsoAsk;
    }

    public function setPeopleAlsoAsk(?array $peopleAlsoAsk): static
    {
        $this->peopleAlsoAsk = $peopleAlsoAsk;
        return $this;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Get difficulty label based on score
     */
    public function getDifficultyLabel(): string
    {
        if ($this->difficulty === null) {
            return 'unknown';
        }

        return match (true) {
            $this->difficulty <= 20 => 'very_easy',
            $this->difficulty <= 40 => 'easy',
            $this->difficulty <= 60 => 'medium',
            $this->difficulty <= 80 => 'hard',
            default => 'very_hard',
        };
    }

    /**
     * Get traffic potential estimate
     */
    public function getTrafficPotential(): ?int
    {
        if ($this->searchVolume === null) {
            return null;
        }

        // Estimate based on typical CTR for top positions
        // Position 1: ~30%, Position 2: ~15%, Position 3: ~10%
        return (int) ($this->searchVolume * 0.30);
    }
}
