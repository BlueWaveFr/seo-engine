<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'seo_settings')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/seo-settings',
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['seo_settings:read']]
        ),
        new Patch(
            uriTemplate: '/seo-settings',
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['seo_settings:write']]
        ),
    ],
    normalizationContext: ['groups' => ['seo_settings:read']]
)]
class SeoSettings
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['seo_settings:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private string $siteUrl = 'https://waverank.io';

    #[ORM\Column(type: 'text')]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private string $robotsTxt;

    #[ORM\Column]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private bool $sitemapPagesEnabled = true;

    #[ORM\Column]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private bool $sitemapArticlesEnabled = true;

    #[ORM\Column]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private bool $sitemapCategoriesEnabled = true;

    #[ORM\Column]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private bool $sitemapGuidesEnabled = true;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private ?array $excludedPages = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['seo_settings:read', 'seo_settings:write'])]
    private ?array $additionalUrls = [];

    #[ORM\Column]
    #[Groups(['seo_settings:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['seo_settings:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->robotsTxt = $this->getDefaultRobotsTxt();
    }

    private function getDefaultRobotsTxt(): string
    {
        return <<<ROBOTS
User-agent: *
Allow: /

# Sitemaps
Sitemap: https://waverank.io/sitemap.xml

# Disallow admin and API paths
Disallow: /api/
Disallow: /admin/

# Allow search engines to crawl all public content
Allow: /blog/
Allow: /pricing
Allow: /features
Allow: /contact

# Crawl-delay for polite crawling
Crawl-delay: 1
ROBOTS;
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

    public function getSiteUrl(): string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(string $siteUrl): static
    {
        $this->siteUrl = rtrim($siteUrl, '/');
        return $this;
    }

    public function getRobotsTxt(): string
    {
        return $this->robotsTxt;
    }

    public function setRobotsTxt(string $robotsTxt): static
    {
        $this->robotsTxt = $robotsTxt;
        return $this;
    }

    public function isSitemapPagesEnabled(): bool
    {
        return $this->sitemapPagesEnabled;
    }

    public function setSitemapPagesEnabled(bool $sitemapPagesEnabled): static
    {
        $this->sitemapPagesEnabled = $sitemapPagesEnabled;
        return $this;
    }

    public function isSitemapArticlesEnabled(): bool
    {
        return $this->sitemapArticlesEnabled;
    }

    public function setSitemapArticlesEnabled(bool $sitemapArticlesEnabled): static
    {
        $this->sitemapArticlesEnabled = $sitemapArticlesEnabled;
        return $this;
    }

    public function isSitemapCategoriesEnabled(): bool
    {
        return $this->sitemapCategoriesEnabled;
    }

    public function setSitemapCategoriesEnabled(bool $sitemapCategoriesEnabled): static
    {
        $this->sitemapCategoriesEnabled = $sitemapCategoriesEnabled;
        return $this;
    }

    public function isSitemapGuidesEnabled(): bool
    {
        return $this->sitemapGuidesEnabled;
    }

    public function setSitemapGuidesEnabled(bool $sitemapGuidesEnabled): static
    {
        $this->sitemapGuidesEnabled = $sitemapGuidesEnabled;
        return $this;
    }

    public function getExcludedPages(): ?array
    {
        return $this->excludedPages;
    }

    public function setExcludedPages(?array $excludedPages): static
    {
        $this->excludedPages = $excludedPages;
        return $this;
    }

    public function getAdditionalUrls(): ?array
    {
        return $this->additionalUrls;
    }

    public function setAdditionalUrls(?array $additionalUrls): static
    {
        $this->additionalUrls = $additionalUrls;
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
