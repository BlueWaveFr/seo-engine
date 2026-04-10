<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'plugin_licenses')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_license_key', columns: ['license_key'])]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['plugin_license:read', 'plugin_license:details']],
            security: "is_granted('ROLE_USER') and object.getCompany().getId() == user.getCompany().getId()"
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['plugin_license:read']],
            security: "is_granted('ROLE_USER')"
        ),
    ],
    normalizationContext: ['groups' => ['plugin_license:read']]
)]
class PluginLicense
{
    public const TIER_FREE = 'free';
    public const TIER_PRO = 'pro';
    public const TIER_AGENCY = 'agency';

    public const CMS_WORDPRESS = 'wordpress';
    public const CMS_PRESTASHOP = 'prestashop';
    public const CMS_SHOPIFY = 'shopify';

    public const VALID_TIERS = [self::TIER_FREE, self::TIER_PRO, self::TIER_AGENCY];
    public const VALID_CMS = [self::CMS_WORDPRESS, self::CMS_PRESTASHOP, self::CMS_SHOPIFY];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['plugin_license:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['plugin_license:details'])]
    private Company $company;

    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['plugin_license:read'])]
    private string $licenseKey;

    #[ORM\Column(length: 20)]
    #[Groups(['plugin_license:read'])]
    private string $tier = self::TIER_FREE;

    #[ORM\Column(length: 20)]
    #[Groups(['plugin_license:read'])]
    private string $cms;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['plugin_license:read'])]
    private ?string $activatedDomain = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['plugin_license:read'])]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column]
    #[Groups(['plugin_license:read'])]
    private int $maxPublishPerMonth = 5;

    #[ORM\Column]
    #[Groups(['plugin_license:read'])]
    private int $publishUsedThisMonth = 0;

    #[ORM\Column]
    #[Groups(['plugin_license:read'])]
    private bool $auditEnabled = false;

    #[ORM\Column]
    #[Groups(['plugin_license:read'])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['plugin_license:read'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    #[Groups(['plugin_license:read'])]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column]
    #[Groups(['plugin_license:read'])]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->currentPeriodStart = new \DateTimeImmutable();
        $this->currentPeriodEnd = new \DateTimeImmutable('+1 month');
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

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getLicenseKey(): string
    {
        return $this->licenseKey;
    }

    public function setLicenseKey(string $licenseKey): static
    {
        $this->licenseKey = $licenseKey;
        return $this;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setTier(string $tier): static
    {
        if (!in_array($tier, self::VALID_TIERS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid tier "%s"', $tier));
        }
        $this->tier = $tier;
        return $this;
    }

    public function getCms(): string
    {
        return $this->cms;
    }

    public function setCms(string $cms): static
    {
        if (!in_array($cms, self::VALID_CMS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid CMS "%s"', $cms));
        }
        $this->cms = $cms;
        return $this;
    }

    public function getActivatedDomain(): ?string
    {
        return $this->activatedDomain;
    }

    public function setActivatedDomain(?string $activatedDomain): static
    {
        $this->activatedDomain = $activatedDomain;
        return $this;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): static
    {
        $this->activatedAt = $activatedAt;
        return $this;
    }

    public function getMaxPublishPerMonth(): int
    {
        return $this->maxPublishPerMonth;
    }

    public function setMaxPublishPerMonth(int $maxPublishPerMonth): static
    {
        $this->maxPublishPerMonth = $maxPublishPerMonth;
        return $this;
    }

    public function getPublishUsedThisMonth(): int
    {
        return $this->publishUsedThisMonth;
    }

    public function setPublishUsedThisMonth(int $publishUsedThisMonth): static
    {
        $this->publishUsedThisMonth = $publishUsedThisMonth;
        return $this;
    }

    public function incrementPublishUsed(int $count = 1): static
    {
        $this->publishUsedThisMonth += $count;
        return $this;
    }

    public function resetMonthlyUsage(): static
    {
        $this->publishUsedThisMonth = 0;
        return $this;
    }

    public function isAuditEnabled(): bool
    {
        return $this->auditEnabled;
    }

    public function setAuditEnabled(bool $auditEnabled): static
    {
        $this->auditEnabled = $auditEnabled;
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

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(\DateTimeImmutable $start): static
    {
        $this->currentPeriodStart = $start;
        return $this;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(\DateTimeImmutable $end): static
    {
        $this->currentPeriodEnd = $end;
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

    // --- Domain logic ---

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function hasValidAccess(): bool
    {
        return $this->isActive && !$this->isExpired();
    }

    public function isDomainActivated(): bool
    {
        return $this->activatedDomain !== null;
    }

    public function isDomainMatch(string $domain): bool
    {
        if ($this->activatedDomain === null) {
            return false;
        }
        return $this->normalizeDomain($this->activatedDomain) === $this->normalizeDomain($domain);
    }

    public function canPublish(): bool
    {
        if (!$this->hasValidAccess()) {
            return false;
        }

        // Unlimited
        if ($this->maxPublishPerMonth === -1) {
            return true;
        }

        return $this->publishUsedThisMonth < $this->maxPublishPerMonth;
    }

    #[Groups(['plugin_license:read'])]
    public function getPublishRemaining(): int
    {
        if ($this->maxPublishPerMonth === -1) {
            return -1;
        }
        return max(0, $this->maxPublishPerMonth - $this->publishUsedThisMonth);
    }

    public function canAudit(string $requestDomain): bool
    {
        if (!$this->hasValidAccess()) {
            return false;
        }

        if (!$this->auditEnabled) {
            return false;
        }

        // Audit only works on the activated domain
        return $this->isDomainMatch($requestDomain);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        // Remove protocol
        $domain = preg_replace('#^https?://#', '', $domain);
        // Remove trailing slash
        $domain = rtrim($domain, '/');
        // Remove www.
        $domain = preg_replace('#^www\.#', '', $domain);
        return $domain;
    }
}
