<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['subscription:read', 'subscription:details']]),
        new GetCollection(normalizationContext: ['groups' => ['subscription:read']]),
    ],
    normalizationContext: ['groups' => ['subscription:read']]
)]
class Subscription
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_TRIAL_EXPIRED = 'trial_expired';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['subscription:read', 'company:read'])]
    private Uuid $id;

    #[ORM\OneToOne(inversedBy: 'subscription', targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['subscription:details'])]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['subscription:read', 'company:read'])]
    private Plan $plan;

    #[ORM\Column(length: 20)]
    #[Groups(['subscription:read', 'company:read'])]
    private string $status = self::STATUS_TRIALING;

    #[ORM\Column]
    #[Groups(['subscription:read', 'company:read'])]
    private int $requestsUsedThisMonth = 0;

    #[ORM\Column]
    #[Groups(['subscription:read', 'company:read'])]
    private int $auditsUsedThisMonth = 0;

    #[ORM\Column]
    #[Groups(['subscription:read'])]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column]
    #[Groups(['subscription:read'])]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(nullable: true)]
    #[Groups(['subscription:read'])]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column]
    #[Groups(['subscription:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['subscription:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->currentPeriodStart = new \DateTimeImmutable();
        $this->currentPeriodEnd = new \DateTimeImmutable('+1 month');
        $this->trialEndsAt = new \DateTimeImmutable('+14 days');
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

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRequestsUsedThisMonth(): int
    {
        return $this->requestsUsedThisMonth;
    }

    public function setRequestsUsedThisMonth(int $requestsUsedThisMonth): static
    {
        $this->requestsUsedThisMonth = $requestsUsedThisMonth;
        return $this;
    }

    public function incrementRequestsUsed(): static
    {
        $this->requestsUsedThisMonth++;
        return $this;
    }

    public function resetMonthlyRequests(): static
    {
        $this->requestsUsedThisMonth = 0;
        $this->auditsUsedThisMonth = 0;
        return $this;
    }

    public function getAuditsUsedThisMonth(): int
    {
        return $this->auditsUsedThisMonth;
    }

    public function setAuditsUsedThisMonth(int $auditsUsedThisMonth): static
    {
        $this->auditsUsedThisMonth = $auditsUsedThisMonth;
        return $this;
    }

    public function incrementAuditsUsed(): static
    {
        $this->auditsUsedThisMonth++;
        return $this;
    }

    public function canRunAudit(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE && $this->status !== self::STATUS_TRIALING) {
            return false;
        }

        $limit = $this->plan->getMonthlyAudits();
        if ($limit === -1) {
            return true;
        }

        return $this->auditsUsedThisMonth < $limit;
    }

    #[Groups(['subscription:read', 'company:read'])]
    public function getAuditsRemaining(): int
    {
        $limit = $this->plan->getMonthlyAudits();
        if ($limit === -1) {
            return -1;
        }
        return max(0, $limit - $this->auditsUsedThisMonth);
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(\DateTimeImmutable $currentPeriodStart): static
    {
        $this->currentPeriodStart = $currentPeriodStart;
        return $this;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): static
    {
        $this->stripePriceId = $stripePriceId;
        return $this;
    }

    #[Groups(['subscription:read', 'company:read'])]
    public function getPlanDetails(): array
    {
        $plan = $this->plan;

        // Convert features collection to array format expected by frontend
        $features = [];
        foreach ($plan->getFeatures() as $feature) {
            // Convert feature name to snake_case key
            $key = $this->featureNameToKey($feature->getName());
            $features[$key] = $feature->isIncluded();
        }

        return [
            'name' => $plan->getName(),
            'slug' => $plan->getSlug(),
            'description' => $plan->getDescription(),
            'max_users' => $plan->getMaxUsers(),
            'monthly_requests' => $plan->getMonthlyRequests(),
            'monthly_audits' => $plan->getMonthlyAudits(),
            'max_projects' => $plan->getMaxProjects(),
            'price_monthly' => $plan->getPriceMonthly(),
            'price_yearly' => $plan->getPriceYearly(),
            'is_free_plan' => $plan->getSlug() === 'free' || $plan->getSlug() === 'offert',
            'features' => $features,
        ];
    }

    private function featureNameToKey(string $name): string
    {
        // Map French feature names to keys
        $mapping = [
            'Génération de contenu IA' => 'ai_content',
            'Audit SEO basique' => 'basic_audit',
            'Audit SEO avancé' => 'advanced_audit',
            'Audit SEO complet' => 'advanced_audit',
            'Export PDF' => 'pdf_export',
            'Export PDF & Word' => 'pdf_export',
            'Intégration Ahrefs' => 'ahrefs',
            'Intégration Ahrefs (Bientôt disponible)' => 'ahrefs',
            'Export limité' => 'limited_export',
            'Google Search Console' => 'search_console',
            'Intégration Search Console' => 'search_console',
        ];

        return $mapping[$name] ?? strtolower(str_replace([' ', '-'], '_', $name));
    }

    #[Groups(['subscription:read', 'company:read'])]
    public function getRequestsRemaining(): int
    {
        $limit = $this->plan->getMonthlyRequests();
        return max(0, $limit - $this->requestsUsedThisMonth);
    }

    public function canMakeRequest(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE && $this->status !== self::STATUS_TRIALING) {
            return false;
        }

        return $this->getRequestsRemaining() > 0;
    }

    public function canAddUser(int $currentUserCount): bool
    {
        $maxUsers = $this->plan->getMaxUsers();
        return $maxUsers === -1 || $currentUserCount < $maxUsers;
    }

    public function canAddProject(int $currentProjectCount): bool
    {
        $maxProjects = $this->plan->getMaxProjects();
        return $maxProjects === -1 || $currentProjectCount < $maxProjects;
    }

    public function isTrialing(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trialEndsAt !== null
            && $this->trialEndsAt > new \DateTimeImmutable();
    }

    public function isTrialExpired(): bool
    {
        if ($this->status === self::STATUS_TRIAL_EXPIRED) {
            return true;
        }

        return $this->status === self::STATUS_TRIALING
            && $this->trialEndsAt !== null
            && $this->trialEndsAt <= new \DateTimeImmutable();
    }

    #[Groups(['subscription:read', 'company:read'])]
    public function getDaysLeftInTrial(): ?int
    {
        if (!$this->trialEndsAt || $this->status !== self::STATUS_TRIALING) {
            return null;
        }

        $now = new \DateTimeImmutable();
        if ($this->trialEndsAt <= $now) {
            return 0;
        }

        $diff = $now->diff($this->trialEndsAt);
        return $diff->days;
    }

    public function hasActiveAccess(): bool
    {
        // Active subscription
        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }

        // Valid trial period
        if ($this->isTrialing()) {
            return true;
        }

        // Past due but within grace period (7 days)
        if ($this->status === self::STATUS_PAST_DUE) {
            $gracePeriodEnd = $this->currentPeriodEnd->modify('+7 days');
            return new \DateTimeImmutable() <= $gracePeriodEnd;
        }

        return false;
    }
}
