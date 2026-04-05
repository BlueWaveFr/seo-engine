<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\Plan;
use SeoExpert\Engine\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

class SubscriptionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function createTrialSubscription(Company $company): Subscription
    {
        // Find the starter plan in database
        $starterPlan = $this->entityManager->getRepository(Plan::class)
            ->findOneBy(['slug' => 'starter']);

        if (!$starterPlan) {
            throw new \RuntimeException('Starter plan not found in database');
        }

        $subscription = new Subscription();
        $subscription->setCompany($company);
        $subscription->setPlan($starterPlan);
        $subscription->setStatus(Subscription::STATUS_TRIALING);
        $subscription->setTrialEndsAt(new \DateTimeImmutable('+14 days'));

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function createFreeSubscription(Company $company): Subscription
    {
        $freePlan = $this->entityManager->getRepository(Plan::class)
            ->findOneBy(['slug' => 'free']);

        if (!$freePlan) {
            throw new \RuntimeException('Free plan not found in database');
        }

        $subscription = new Subscription();
        $subscription->setCompany($company);
        $subscription->setPlan($freePlan);
        $subscription->setStatus(Subscription::STATUS_ACTIVE);
        $subscription->setTrialEndsAt(null);

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function canMakeRequest(Subscription $subscription): bool
    {
        // Check if subscription has active access (handles trial expiration)
        if (!$subscription->hasActiveAccess()) {
            // Auto-update status if trial expired
            if ($subscription->isTrialExpired() && $subscription->getStatus() === Subscription::STATUS_TRIALING) {
                $subscription->setStatus(Subscription::STATUS_TRIAL_EXPIRED);
                $this->entityManager->flush();
            }
            return false;
        }

        // Check request limits
        return $subscription->canMakeRequest();
    }

    /**
     * Check and update subscription status based on trial/payment state
     */
    public function checkAndUpdateStatus(Subscription $subscription): string
    {
        $previousStatus = $subscription->getStatus();

        // Check if trial has expired
        if ($subscription->getStatus() === Subscription::STATUS_TRIALING) {
            if ($subscription->isTrialExpired()) {
                $subscription->setStatus(Subscription::STATUS_TRIAL_EXPIRED);
                $this->entityManager->flush();
            }
        }

        return $subscription->getStatus();
    }

    /**
     * Get detailed subscription access info for frontend
     */
    public function getAccessInfo(Subscription $subscription): array
    {
        $this->checkAndUpdateStatus($subscription);

        return [
            'has_access' => $subscription->hasActiveAccess(),
            'status' => $subscription->getStatus(),
            'is_trialing' => $subscription->isTrialing(),
            'is_trial_expired' => $subscription->isTrialExpired(),
            'is_free_plan' => $subscription->getPlan()->getSlug() === 'free',
            'days_left_in_trial' => $subscription->getDaysLeftInTrial(),
            'trial_ends_at' => $subscription->getTrialEndsAt()?->format('c'),
            'requires_payment' => $subscription->getStatus() === Subscription::STATUS_TRIAL_EXPIRED
                || $subscription->getStatus() === Subscription::STATUS_PAST_DUE,
            'can_make_request' => $this->canMakeRequest($subscription),
            'can_run_audit' => $subscription->canRunAudit(),
        ];
    }

    public function incrementUsage(Subscription $subscription): void
    {
        $subscription->incrementRequestsUsed();
        $this->entityManager->flush();
    }

    public function canRunAudit(Subscription $subscription): bool
    {
        if (!$subscription->hasActiveAccess()) {
            if ($subscription->isTrialExpired() && $subscription->getStatus() === Subscription::STATUS_TRIALING) {
                $subscription->setStatus(Subscription::STATUS_TRIAL_EXPIRED);
                $this->entityManager->flush();
            }
            return false;
        }

        return $subscription->canRunAudit();
    }

    public function incrementAuditUsage(Subscription $subscription): void
    {
        $subscription->incrementAuditsUsed();
        $this->entityManager->flush();
    }

    public function canAddUser(Company $company): bool
    {
        $subscription = $company->getSubscription();
        if (!$subscription) {
            return false;
        }

        $currentUserCount = $company->getUsers()->count();
        return $subscription->canAddUser($currentUserCount);
    }

    public function canAddProject(Company $company): bool
    {
        $subscription = $company->getSubscription();
        if (!$subscription) {
            return false;
        }

        $currentProjectCount = $company->getProjects()->count();
        return $subscription->canAddProject($currentProjectCount);
    }

    public function upgradePlan(Subscription $subscription, string $newPlanSlug): void
    {
        $plan = $this->entityManager->getRepository(Plan::class)
            ->findOneBy(['slug' => $newPlanSlug]);

        if (!$plan) {
            throw new \InvalidArgumentException('Invalid plan: ' . $newPlanSlug);
        }

        $subscription->setPlan($plan);
        $this->entityManager->flush();
    }

    public function resetMonthlyUsage(Subscription $subscription): void
    {
        $subscription->resetMonthlyRequests();
        $subscription->setCurrentPeriodStart(new \DateTimeImmutable());
        $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('+1 month'));
        $this->entityManager->flush();
    }

    public function getUsageStats(Subscription $subscription): array
    {
        $planDetails = $subscription->getPlanDetails();
        $monthlyRequests = $planDetails['monthly_requests'];
        $monthlyAudits = $planDetails['monthly_audits'];

        return [
            'plan' => $subscription->getPlan(),
            'plan_name' => $planDetails['name'],
            'is_free_plan' => $planDetails['is_free_plan'],
            'requests_used' => $subscription->getRequestsUsedThisMonth(),
            'requests_limit' => $monthlyRequests,
            'requests_remaining' => $subscription->getRequestsRemaining(),
            'usage_percentage' => $monthlyRequests > 0 ? round(
                ($subscription->getRequestsUsedThisMonth() / $monthlyRequests) * 100,
                1
            ) : 0,
            'audits_used' => $subscription->getAuditsUsedThisMonth(),
            'audits_limit' => $monthlyAudits,
            'audits_remaining' => $subscription->getAuditsRemaining(),
            'audits_percentage' => $monthlyAudits > 0 ? round(
                ($subscription->getAuditsUsedThisMonth() / $monthlyAudits) * 100,
                1
            ) : 0,
            'period_start' => $subscription->getCurrentPeriodStart()->format('Y-m-d'),
            'period_end' => $subscription->getCurrentPeriodEnd()->format('Y-m-d'),
            'is_trialing' => $subscription->isTrialing(),
            'trial_ends_at' => $subscription->getTrialEndsAt()?->format('Y-m-d'),
        ];
    }
}
