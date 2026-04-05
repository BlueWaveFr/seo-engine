<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'social_accounts')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['social_account:write']]
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['social_account:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['social_account:read']]
)]
class SocialAccount
{
    public const PLATFORM_X = 'x';
    public const PLATFORM_LINKEDIN = 'linkedin';
    public const PLATFORM_FACEBOOK = 'facebook';
    public const PLATFORM_INSTAGRAM = 'instagram';

    public const PLATFORMS = [
        self::PLATFORM_X => 'X (Twitter)',
        self::PLATFORM_LINKEDIN => 'LinkedIn',
        self::PLATFORM_FACEBOOK => 'Facebook',
        self::PLATFORM_INSTAGRAM => 'Instagram',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['social_account:read'])]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: 'getPlatformKeys')]
    #[Groups(['social_account:read', 'social_account:write'])]
    private string $platform;

    #[ORM\Column(length: 100)]
    #[Groups(['social_account:read', 'social_account:write'])]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['social_account:read', 'social_account:write'])]
    private ?string $profileUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['social_account:write'])]
    private ?string $accessToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['social_account:write'])]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['social_account:write'])]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['social_account:write'])]
    private ?string $apiSecret = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column]
    #[Groups(['social_account:read', 'social_account:write'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['social_account:read', 'social_account:write'])]
    private bool $autoPublish = false;

    #[ORM\Column]
    #[Groups(['social_account:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['social_account:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function getPlatformKeys(): array
    {
        return array_keys(self::PLATFORMS);
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

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): static
    {
        $this->platform = $platform;
        return $this;
    }

    #[Groups(['social_account:read'])]
    public function getPlatformName(): string
    {
        return self::PLATFORMS[$this->platform] ?? $this->platform;
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

    public function getProfileUrl(): ?string
    {
        return $this->profileUrl;
    }

    public function setProfileUrl(?string $profileUrl): static
    {
        $this->profileUrl = $profileUrl;
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

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
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

    public function isAutoPublish(): bool
    {
        return $this->autoPublish;
    }

    public function getAutoPublish(): bool
    {
        return $this->autoPublish;
    }

    public function setAutoPublish(bool $autoPublish): static
    {
        $this->autoPublish = $autoPublish;
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

    #[Groups(['social_account:read'])]
    public function hasValidToken(): bool
    {
        if (!$this->accessToken) {
            return false;
        }

        if ($this->tokenExpiresAt && $this->tokenExpiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return true;
    }
}
