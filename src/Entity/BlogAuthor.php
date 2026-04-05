<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'blog_authors')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['blog_author:read', 'blog_author:details']]),
        new GetCollection(normalizationContext: ['groups' => ['blog_author:read']]),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['blog_author:write']]
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['blog_author:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['blog_author:read']],
    order: ['name' => 'ASC']
)]
class BlogAuthor
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['blog_author:read', 'blog_article:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private ?string $company = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private ?string $bio = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?string $fullBio = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private ?string $avatar = null;

    // EEAT Social Links
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private ?string $linkedinUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write', 'blog_article:read'])]
    private ?string $twitterUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?string $githubUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?string $websiteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?string $email = null;

    // EEAT Credentials
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?array $credentials = null; // certifications, diplomes, etc.

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?array $expertise = null; // domaines d'expertise

    #[ORM\Column(nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?int $yearsOfExperience = null;

    // SEO Meta
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private ?string $metaDescription = null;

    #[ORM\Column]
    #[Groups(['blog_author:read', 'blog_author:write'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['blog_author:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['blog_author:read'])]
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;
        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;
        return $this;
    }

    public function getFullBio(): ?string
    {
        return $this->fullBio;
    }

    public function setFullBio(?string $fullBio): static
    {
        $this->fullBio = $fullBio;
        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;
        return $this;
    }

    public function getLinkedinUrl(): ?string
    {
        return $this->linkedinUrl;
    }

    public function setLinkedinUrl(?string $linkedinUrl): static
    {
        $this->linkedinUrl = $linkedinUrl;
        return $this;
    }

    public function getTwitterUrl(): ?string
    {
        return $this->twitterUrl;
    }

    public function setTwitterUrl(?string $twitterUrl): static
    {
        $this->twitterUrl = $twitterUrl;
        return $this;
    }

    public function getGithubUrl(): ?string
    {
        return $this->githubUrl;
    }

    public function setGithubUrl(?string $githubUrl): static
    {
        $this->githubUrl = $githubUrl;
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getCredentials(): ?array
    {
        return $this->credentials;
    }

    public function setCredentials(?array $credentials): static
    {
        $this->credentials = $credentials;
        return $this;
    }

    public function getExpertise(): ?array
    {
        return $this->expertise;
    }

    public function setExpertise(?array $expertise): static
    {
        $this->expertise = $expertise;
        return $this;
    }

    public function getYearsOfExperience(): ?int
    {
        return $this->yearsOfExperience;
    }

    public function setYearsOfExperience(?int $yearsOfExperience): static
    {
        $this->yearsOfExperience = $yearsOfExperience;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Generate Schema.org Person structured data for EEAT
     */
    #[Groups(['blog_author:read', 'blog_author:details'])]
    public function getSchemaOrg(): array
    {
        $schema = [
            '@type' => 'Person',
            'name' => $this->name,
            'url' => 'https://waverank.io/blog/auteur/' . $this->slug,
        ];

        if ($this->jobTitle) {
            $schema['jobTitle'] = $this->jobTitle;
        }

        if ($this->bio) {
            $schema['description'] = $this->bio;
        }

        if ($this->avatar) {
            $schema['image'] = $this->avatar;
        }

        if ($this->email) {
            $schema['email'] = $this->email;
        }

        // Social links for EEAT
        $sameAs = [];
        if ($this->linkedinUrl) {
            $sameAs[] = $this->linkedinUrl;
        }
        if ($this->twitterUrl) {
            $sameAs[] = $this->twitterUrl;
        }
        if ($this->githubUrl) {
            $sameAs[] = $this->githubUrl;
        }
        if ($this->websiteUrl) {
            $sameAs[] = $this->websiteUrl;
        }
        if (!empty($sameAs)) {
            $schema['sameAs'] = $sameAs;
        }

        // Work organization
        if ($this->company) {
            $schema['worksFor'] = [
                '@type' => 'Organization',
                'name' => $this->company,
            ];
        }

        // Expertise (knows about)
        if (!empty($this->expertise)) {
            $schema['knowsAbout'] = $this->expertise;
        }

        return $schema;
    }

    /**
     * Calculate EEAT score for this author
     */
    #[Groups(['blog_author:read'])]
    public function getEeatScore(): int
    {
        $score = 0;
        $maxScore = 100;

        // Name (required) - 10 points
        if ($this->name) {
            $score += 10;
        }

        // Bio - 10 points
        if ($this->bio && strlen($this->bio) > 50) {
            $score += 10;
        }

        // Avatar - 10 points
        if ($this->avatar) {
            $score += 10;
        }

        // Job title - 10 points
        if ($this->jobTitle) {
            $score += 10;
        }

        // Company - 5 points
        if ($this->company) {
            $score += 5;
        }

        // LinkedIn (important for EEAT) - 15 points
        if ($this->linkedinUrl) {
            $score += 15;
        }

        // Twitter - 10 points
        if ($this->twitterUrl) {
            $score += 10;
        }

        // Website - 5 points
        if ($this->websiteUrl) {
            $score += 5;
        }

        // Credentials - 10 points
        if (!empty($this->credentials)) {
            $score += 10;
        }

        // Expertise - 5 points
        if (!empty($this->expertise)) {
            $score += 5;
        }

        // Full bio - extra 5 points
        if ($this->fullBio && strlen($this->fullBio) > 200) {
            $score += 5;
        }

        return min($score, $maxScore);
    }

    #[Groups(['blog_author:read'])]
    public function getEeatLevel(): string
    {
        $score = $this->getEeatScore();
        if ($score >= 80) {
            return 'excellent';
        }
        if ($score >= 60) {
            return 'good';
        }
        if ($score >= 40) {
            return 'average';
        }
        return 'needs_improvement';
    }
}
