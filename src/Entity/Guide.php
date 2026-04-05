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
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'guides')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['is_published', 'published_at'], name: 'idx_guide_published')]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['guide:read', 'guide:details']]),
        new GetCollection(normalizationContext: ['groups' => ['guide:read']]),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['guide:write']]
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['guide:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['guide:read']],
    order: ['publishedAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: ['slug' => 'exact', 'category' => 'exact', 'difficulty' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isPublished', 'isFeatured'])]
#[ApiFilter(OrderFilter::class, properties: ['publishedAt', 'createdAt', 'viewCount'])]
class Guide
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['guide:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['guide:read', 'guide:write'])]
    private string $title;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['guide:read', 'guide:write'])]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['guide:read', 'guide:details', 'guide:write'])]
    private ?string $introduction = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['guide:read', 'guide:details', 'guide:write'])]
    private ?string $conclusion = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $featuredImage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $featuredImageAlt = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $category = null;

    #[ORM\Column(length: 50)]
    #[Assert\Choice(choices: ['beginner', 'intermediate', 'advanced'])]
    #[Groups(['guide:read', 'guide:write'])]
    private string $difficulty = 'beginner';

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?array $prerequisites = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?array $learningObjectives = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?array $targetAudience = null;

    #[ORM\OneToMany(mappedBy: 'guide', targetEntity: GuideChapter::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['guide:read', 'guide:details', 'guide:write'])]
    private Collection $chapters;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['guide:read', 'guide:write'])]
    private User $author;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $customAuthorName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $authorBio = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $authorAvatar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?array $metaKeywords = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?string $canonicalUrl = null;

    #[ORM\Column]
    #[Groups(['guide:read', 'guide:write'])]
    private bool $isPublished = false;

    #[ORM\Column]
    #[Groups(['guide:read', 'guide:write'])]
    private bool $isFeatured = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    #[Groups(['guide:read'])]
    private int $viewCount = 0;

    #[ORM\Column]
    #[Groups(['guide:read'])]
    private int $totalReadingTime = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:details'])]
    private ?array $tableOfContents = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?array $relatedGuides = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide:read', 'guide:write'])]
    private ?array $schema = null;

    #[ORM\Column]
    #[Groups(['guide:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['guide:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->chapters = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onPrePersist(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->calculateTotalReadingTime();
        $this->generateTableOfContents();
    }

    private function calculateTotalReadingTime(): void
    {
        $totalWords = 0;

        if ($this->introduction) {
            $totalWords += str_word_count(strip_tags($this->introduction));
        }

        foreach ($this->chapters as $chapter) {
            $totalWords += str_word_count(strip_tags($chapter->getContent() ?? ''));
        }

        if ($this->conclusion) {
            $totalWords += str_word_count(strip_tags($this->conclusion));
        }

        $this->totalReadingTime = max(1, (int) ceil($totalWords / 200));
    }

    private function generateTableOfContents(): void
    {
        $toc = [];

        foreach ($this->chapters as $chapter) {
            $chapterToc = [
                'id' => (string) $chapter->getId(),
                'title' => $chapter->getTitle(),
                'slug' => $chapter->getSlug(),
                'position' => $chapter->getPosition(),
                'readingTime' => $chapter->getReadingTime(),
                'sections' => [],
            ];

            // Extract h2/h3 from chapter content
            if ($chapter->getContent()) {
                preg_match_all('/<h([2-3])[^>]*>(.*?)<\/h\1>/i', $chapter->getContent(), $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $level = (int) $match[1];
                    $text = strip_tags($match[2]);
                    $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
                    $chapterToc['sections'][] = [
                        'level' => $level,
                        'text' => $text,
                        'id' => $id,
                    ];
                }
            }

            $toc[] = $chapterToc;
        }

        $this->tableOfContents = $toc;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
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

    public function getIntroduction(): ?string
    {
        return $this->introduction;
    }

    public function setIntroduction(?string $introduction): static
    {
        $this->introduction = $introduction;
        return $this;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function setConclusion(?string $conclusion): static
    {
        $this->conclusion = $conclusion;
        return $this;
    }

    public function getFeaturedImage(): ?string
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?string $featuredImage): static
    {
        $this->featuredImage = $featuredImage;
        return $this;
    }

    public function getFeaturedImageAlt(): ?string
    {
        return $this->featuredImageAlt;
    }

    public function setFeaturedImageAlt(?string $featuredImageAlt): static
    {
        $this->featuredImageAlt = $featuredImageAlt;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): static
    {
        $this->difficulty = $difficulty;
        return $this;
    }

    public function getPrerequisites(): ?array
    {
        return $this->prerequisites;
    }

    public function setPrerequisites(?array $prerequisites): static
    {
        $this->prerequisites = $prerequisites;
        return $this;
    }

    public function getLearningObjectives(): ?array
    {
        return $this->learningObjectives;
    }

    public function setLearningObjectives(?array $learningObjectives): static
    {
        $this->learningObjectives = $learningObjectives;
        return $this;
    }

    public function getTargetAudience(): ?array
    {
        return $this->targetAudience;
    }

    public function setTargetAudience(?array $targetAudience): static
    {
        $this->targetAudience = $targetAudience;
        return $this;
    }

    /**
     * @return Collection<int, GuideChapter>
     */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function addChapter(GuideChapter $chapter): static
    {
        if (!$this->chapters->contains($chapter)) {
            $this->chapters->add($chapter);
            $chapter->setGuide($this);
        }
        return $this;
    }

    public function removeChapter(GuideChapter $chapter): static
    {
        if ($this->chapters->removeElement($chapter)) {
            if ($chapter->getGuide() === $this) {
                $chapter->setGuide(null);
            }
        }
        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getCustomAuthorName(): ?string
    {
        return $this->customAuthorName;
    }

    public function setCustomAuthorName(?string $customAuthorName): static
    {
        $this->customAuthorName = $customAuthorName;
        return $this;
    }

    public function getAuthorBio(): ?string
    {
        return $this->authorBio;
    }

    public function setAuthorBio(?string $authorBio): static
    {
        $this->authorBio = $authorBio;
        return $this;
    }

    public function getAuthorAvatar(): ?string
    {
        return $this->authorAvatar;
    }

    public function setAuthorAvatar(?string $authorAvatar): static
    {
        $this->authorAvatar = $authorAvatar;
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

    public function getMetaKeywords(): ?array
    {
        return $this->metaKeywords;
    }

    public function setMetaKeywords(?array $metaKeywords): static
    {
        $this->metaKeywords = $metaKeywords;
        return $this;
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(?string $canonicalUrl): static
    {
        $this->canonicalUrl = $canonicalUrl;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function getIsPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;
        if ($isPublished && !$this->publishedAt) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function getIsFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;
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

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function incrementViewCount(): static
    {
        $this->viewCount++;
        return $this;
    }

    public function getTotalReadingTime(): int
    {
        return $this->totalReadingTime;
    }

    public function getTableOfContents(): ?array
    {
        return $this->tableOfContents;
    }

    public function getRelatedGuides(): ?array
    {
        return $this->relatedGuides;
    }

    public function setRelatedGuides(?array $relatedGuides): static
    {
        $this->relatedGuides = $relatedGuides;
        return $this;
    }

    public function getSchema(): ?array
    {
        return $this->schema;
    }

    public function setSchema(?array $schema): static
    {
        $this->schema = $schema;
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

    #[Groups(['guide:read'])]
    public function getAuthorName(): string
    {
        return $this->customAuthorName ?? ($this->author->getFirstName() . ' ' . $this->author->getLastName());
    }

    #[Groups(['guide:read'])]
    public function getChapterCount(): int
    {
        return $this->chapters->count();
    }

    #[Groups(['guide:read'])]
    public function getFormattedDate(): string
    {
        $date = $this->publishedAt ?? $this->createdAt;
        return $date->format('d/m/Y');
    }

    #[Groups(['guide:read'])]
    public function getDifficultyLabel(): string
    {
        return match($this->difficulty) {
            'beginner' => 'Débutant',
            'intermediate' => 'Intermédiaire',
            'advanced' => 'Avancé',
            default => $this->difficulty,
        };
    }
}
