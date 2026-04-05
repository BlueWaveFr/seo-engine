<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Delete;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'api_keys')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['api_key:read']]
        ),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['api_key:read']]
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['api_key:write']]
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['api_key:write']]
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')"
        ),
    ],
    normalizationContext: ['groups' => ['api_key:read']]
)]
class ApiKey
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_AHREFS = 'ahrefs';
    public const PROVIDER_GOOGLE_OAUTH = 'google_oauth';
    public const PROVIDER_GOOGLE_INDEXING = 'google_indexing';
    public const PROVIDER_GOOGLE_SEARCH_CONSOLE = 'google_search_console';
    public const PROVIDER_GOOGLE_PAGESPEED = 'google_pagespeed';
    public const PROVIDER_GOOGLE_SAFE_BROWSING = 'google_safe_browsing';
    public const PROVIDER_GOOGLE_CUSTOM_SEARCH = 'google_custom_search';
    public const PROVIDER_BING_WEBMASTER = 'bing_webmaster';
    public const PROVIDER_BING_SEARCH = 'bing_search';
    public const PROVIDER_LINKEDIN = 'linkedin';
    public const PROVIDER_X = 'x_twitter';
    public const PROVIDER_SCREAMING_FROG = 'screaming_frog';
    public const PROVIDER_MAJESTIC = 'majestic';
    public const PROVIDER_MOZ = 'moz';
    public const PROVIDER_SEMRUSH = 'semrush';
    public const PROVIDER_SITEBULB = 'sitebulb';
    public const PROVIDER_COMMONCRAWL = 'commoncrawl';
    public const PROVIDER_WAYBACK_MACHINE = 'wayback_machine';
    public const PROVIDER_SSL_LABS = 'ssl_labs';
    public const PROVIDER_W3C_VALIDATOR = 'w3c_validator';
    public const PROVIDER_CLOUDFLARE = 'cloudflare';
    public const PROVIDER_DATAFORSEO = 'dataforseo';

    public const PROVIDERS = [
        self::PROVIDER_ANTHROPIC => 'Anthropic (Claude AI)',
        self::PROVIDER_AHREFS => 'Ahrefs',
        self::PROVIDER_GOOGLE_OAUTH => 'Google OAuth (Search Console)',
        self::PROVIDER_GOOGLE_INDEXING => 'Google Indexing API',
        self::PROVIDER_GOOGLE_SEARCH_CONSOLE => 'Google Search Console',
        self::PROVIDER_GOOGLE_PAGESPEED => 'Google PageSpeed Insights',
        self::PROVIDER_GOOGLE_SAFE_BROWSING => 'Google Safe Browsing',
        self::PROVIDER_GOOGLE_CUSTOM_SEARCH => 'Google Custom Search',
        self::PROVIDER_BING_WEBMASTER => 'Bing Webmaster API',
        self::PROVIDER_BING_SEARCH => 'Bing Search API',
        self::PROVIDER_LINKEDIN => 'LinkedIn OAuth',
        self::PROVIDER_X => 'X (Twitter) OAuth',
        self::PROVIDER_SCREAMING_FROG => 'Screaming Frog',
        self::PROVIDER_MAJESTIC => 'Majestic',
        self::PROVIDER_MOZ => 'Moz',
        self::PROVIDER_SEMRUSH => 'SEMrush',
        self::PROVIDER_SITEBULB => 'Sitebulb',
        self::PROVIDER_COMMONCRAWL => 'CommonCrawl',
        self::PROVIDER_WAYBACK_MACHINE => 'Wayback Machine',
        self::PROVIDER_SSL_LABS => 'SSL Labs',
        self::PROVIDER_W3C_VALIDATOR => 'W3C Validator',
        self::PROVIDER_CLOUDFLARE => 'Cloudflare',
        self::PROVIDER_DATAFORSEO => 'DataForSEO',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['api_key:read'])]
    private Uuid $id;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: 'getProviderKeys')]
    #[Groups(['api_key:read', 'api_key:write'])]
    private string $provider;

    #[ORM\Column(length: 255)]
    #[Groups(['api_key:read'])]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['api_key:read', 'api_key:write'])]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['api_key:read', 'api_key:write'])]
    private ?string $apiSecret = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['api_key:read', 'api_key:write'])]
    private ?string $accessToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['api_key:read', 'api_key:write'])]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['api_key:read', 'api_key:write'])]
    private ?array $additionalConfig = null;

    #[ORM\Column]
    #[Groups(['api_key:read', 'api_key:write'])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['api_key:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    #[Groups(['api_key:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['api_key:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function getProviderKeys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->name = self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->name = self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        $this->name = self::PROVIDERS[$provider] ?? $provider;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): static
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function getApiSecret(): ?string
    {
        return $this->apiSecret;
    }

    public function setApiSecret(?string $apiSecret): static
    {
        $this->apiSecret = $apiSecret;
        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): static
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getAdditionalConfig(): ?array
    {
        return $this->additionalConfig;
    }

    public function setAdditionalConfig(?array $additionalConfig): static
    {
        $this->additionalConfig = $additionalConfig;
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

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function markAsUsed(): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();
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

    public function hasCredentials(): bool
    {
        return $this->apiKey !== null || $this->accessToken !== null;
    }
}
