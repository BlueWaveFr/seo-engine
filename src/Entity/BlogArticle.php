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
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'blog_articles')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['is_published', 'published_at'], name: 'idx_published')]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['blog_article:read', 'blog_article:details']]),
        new GetCollection(normalizationContext: ['groups' => ['blog_article:read']]),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['blog_article:write']]
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['blog_article:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['blog_article:read']],
    order: ['publishedAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: ['category.slug' => 'exact', 'slug' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isPublished', 'isFeatured'])]
#[ApiFilter(OrderFilter::class, properties: ['publishedAt', 'createdAt', 'viewCount'])]
class BlogArticle
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['blog_article:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $titleEn = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $excerpt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $excerptEn = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['blog_article:read', 'blog_article:details', 'blog_article:write'])]
    private string $content;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:details', 'blog_article:write'])]
    private ?string $contentEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $featuredImage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $featuredImageAlt = null;

    #[ORM\ManyToOne(targetEntity: BlogCategory::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private BlogCategory $category;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private User $author;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $customAuthorName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $authorBio = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $authorAvatar = null;

    #[ORM\ManyToOne(targetEntity: BlogAuthor::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?BlogAuthor $blogAuthor = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $metaTitleEn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $metaDescriptionEn = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?array $metaKeywords = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?string $canonicalUrl = null;

    #[ORM\Column]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private bool $isPublished = false;

    #[ORM\Column]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private bool $isFeatured = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    #[Groups(['blog_article:read'])]
    private int $viewCount = 0;

    #[ORM\Column]
    #[Groups(['blog_article:read'])]
    private int $readingTime = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?array $socialShares = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:details'])]
    private ?array $tableOfContents = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['blog_article:read', 'blog_article:write'])]
    private ?array $schema = null;

    #[ORM\Column]
    #[Groups(['blog_article:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['blog_article:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onPrePersist(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->calculateReadingTime();
        $this->generateTableOfContents();
    }

    private function calculateReadingTime(): void
    {
        $wordCount = str_word_count(strip_tags($this->content));
        $this->readingTime = max(1, (int) ceil($wordCount / 200));
    }

    private function generateTableOfContents(): void
    {
        preg_match_all('/<h([2-3])[^>]*>(.*?)<\/h\1>/i', $this->content, $matches, PREG_SET_ORDER);

        $toc = [];
        foreach ($matches as $match) {
            $level = (int) $match[1];
            $text = strip_tags($match[2]);
            $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));

            $toc[] = [
                'level' => $level,
                'text' => $text,
                'id' => $id,
            ];
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

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): static
    {
        $this->titleEn = $titleEn;
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

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = $excerpt;
        return $this;
    }

    public function getExcerptEn(): ?string
    {
        return $this->excerptEn;
    }

    public function setExcerptEn(?string $excerptEn): static
    {
        $this->excerptEn = $excerptEn;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getContentEn(): ?string
    {
        return $this->contentEn;
    }

    public function setContentEn(?string $contentEn): static
    {
        $this->contentEn = $contentEn;
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

    public function getCategory(): BlogCategory
    {
        return $this->category;
    }

    public function setCategory(BlogCategory $category): static
    {
        $this->category = $category;
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

    public function getCustomAuthorName(): ?string
    {
        return $this->customAuthorName;
    }

    public function setCustomAuthorName(?string $customAuthorName): static
    {
        $this->customAuthorName = $customAuthorName;
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

    public function getMetaTitleEn(): ?string
    {
        return $this->metaTitleEn;
    }

    public function setMetaTitleEn(?string $metaTitleEn): static
    {
        $this->metaTitleEn = $metaTitleEn;
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

    public function getMetaDescriptionEn(): ?string
    {
        return $this->metaDescriptionEn;
    }

    public function setMetaDescriptionEn(?string $metaDescriptionEn): static
    {
        $this->metaDescriptionEn = $metaDescriptionEn;
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

    public function getReadingTime(): int
    {
        return $this->readingTime;
    }

    public function getSocialShares(): ?array
    {
        return $this->socialShares;
    }

    public function setSocialShares(?array $socialShares): static
    {
        $this->socialShares = $socialShares;
        return $this;
    }

    public function getTableOfContents(): ?array
    {
        return $this->tableOfContents;
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

    #[Groups(['blog_article:read'])]
    public function getAuthorName(): string
    {
        return $this->customAuthorName ?? ($this->author->getFirstName() . ' ' . $this->author->getLastName());
    }

    #[Groups(['blog_article:read'])]
    public function getFormattedDate(): string
    {
        $date = $this->publishedAt ?? $this->createdAt;
        return $date->format('d/m/Y');
    }

    public function getBlogAuthor(): ?BlogAuthor
    {
        return $this->blogAuthor;
    }

    public function setBlogAuthor(?BlogAuthor $blogAuthor): static
    {
        $this->blogAuthor = $blogAuthor;
        return $this;
    }

    /**
     * Get author data with EEAT enrichment
     */
    #[Groups(['blog_article:read', 'blog_article:details'])]
    public function getAuthorData(): array
    {
        // If we have a BlogAuthor, use it for rich EEAT data
        if ($this->blogAuthor) {
            return [
                'name' => $this->blogAuthor->getName(),
                'slug' => $this->blogAuthor->getSlug(),
                'bio' => $this->blogAuthor->getBio(),
                'avatar' => $this->blogAuthor->getAvatar(),
                'jobTitle' => $this->blogAuthor->getJobTitle(),
                'company' => $this->blogAuthor->getCompany(),
                'linkedinUrl' => $this->blogAuthor->getLinkedinUrl(),
                'twitterUrl' => $this->blogAuthor->getTwitterUrl(),
                'websiteUrl' => $this->blogAuthor->getWebsiteUrl(),
                'expertise' => $this->blogAuthor->getExpertise(),
                'credentials' => $this->blogAuthor->getCredentials(),
                'eeatScore' => $this->blogAuthor->getEeatScore(),
                'eeatLevel' => $this->blogAuthor->getEeatLevel(),
                'schemaOrg' => $this->blogAuthor->getSchemaOrg(),
                'hasAuthorPage' => true,
                'authorPageUrl' => '/blog/auteur/' . $this->blogAuthor->getSlug(),
            ];
        }

        // Fallback to basic author info
        return [
            'name' => $this->getAuthorName(),
            'slug' => null,
            'bio' => $this->authorBio,
            'avatar' => $this->authorAvatar,
            'jobTitle' => null,
            'company' => null,
            'linkedinUrl' => null,
            'twitterUrl' => null,
            'websiteUrl' => null,
            'expertise' => null,
            'credentials' => null,
            'eeatScore' => $this->calculateBasicEeatScore(),
            'eeatLevel' => 'needs_improvement',
            'schemaOrg' => [
                '@type' => 'Person',
                'name' => $this->getAuthorName(),
            ],
            'hasAuthorPage' => false,
            'authorPageUrl' => null,
        ];
    }

    private function calculateBasicEeatScore(): int
    {
        $score = 0;
        if ($this->getAuthorName()) {
            $score += 20;
        }
        if ($this->authorBio && strlen($this->authorBio) > 50) {
            $score += 20;
        }
        if ($this->authorAvatar) {
            $score += 10;
        }
        return $score;
    }
}
