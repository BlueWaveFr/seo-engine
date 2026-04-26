<?php

declare(strict_types=1);

namespace SeoExpert\Engine\Service\Agent;

use SeoExpert\Engine\Entity\Subscription;
use SeoExpert\Engine\Entity\User;
use SeoExpert\Engine\Service\SubscriptionService;

/**
 * Plan-gating for Agent Mode features.
 *
 * Canonical plan slugs (see DB seed / migrations):
 *   - `free`      : free self-service plan, manual-only
 *   - `starter`   : paid entry plan, manual-only (no agent)
 *   - `pro`       : scheduler only (approval inbox + cron)
 *   - `agency`    : full Agent Mode (orchestrator + auto-execution)
 *   - `offert`    : beta-tester plan with agency-level limits (treated as agency)
 *   - `enterprise`: LEGACY alias for `agency` kept for backward compatibility
 *                   (renamed to `agency` in Version20260228FreePlanAndAgency).
 *                   Do not seed new plans with this slug.
 *
 * Features:
 *  - `agent_scheduler` — available on Pro, Agency, Offert (and legacy enterprise) plans.
 *  - `agent_full`      — available on Agency, Offert (and legacy enterprise) plans only.
 *
 * The guard returns simple allow/deny booleans; consumers (controllers) are
 * expected to throw an {@see AccessDeniedHttpException} with the
 * `PLAN_UPGRADE_REQUIRED` code when access is denied.
 */
class AgentPlanGuard
{
    public const FEATURE_AGENT_SCHEDULER = 'agent_scheduler';
    public const FEATURE_AGENT_FULL = 'agent_full';

    public const PLANS_WITH_SCHEDULER = ['pro', 'agency', 'offert', 'enterprise'];
    public const PLANS_WITH_FULL_AGENT = ['agency', 'offert', 'enterprise'];

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * Check whether the given user's subscription allows the feature.
     */
    public function allows(User $user, string $feature): bool
    {
        $subscription = $user->getCompany()?->getSubscription();

        if ($subscription === null) {
            return false;
        }

        // Expired trials / canceled / past-due subscriptions do not get agent features.
        $this->subscriptionService->checkAndUpdateStatus($subscription);
        if (!$subscription->hasActiveAccess()) {
            return false;
        }

        $slug = $subscription->getPlan()?->getSlug();
        if ($slug === null) {
            return false;
        }

        return match ($feature) {
            self::FEATURE_AGENT_SCHEDULER => in_array($slug, self::PLANS_WITH_SCHEDULER, true),
            self::FEATURE_AGENT_FULL      => in_array($slug, self::PLANS_WITH_FULL_AGENT, true),
            default => false,
        };
    }

    /**
     * Return a human-readable reason when access is denied. Useful for error payloads.
     */
    public function denialReason(User $user, string $feature): string
    {
        $subscription = $user->getCompany()?->getSubscription();

        if ($subscription === null) {
            return 'No active subscription found.';
        }

        if (!$subscription->hasActiveAccess()) {
            return 'Your subscription is not active.';
        }

        return match ($feature) {
            self::FEATURE_AGENT_SCHEDULER => 'Agent schedules require a Pro or Agency plan.',
            self::FEATURE_AGENT_FULL      => 'Full Agent Mode requires an Agency plan.',
            default                       => 'This feature is not included in your plan.',
        };
    }

    public function getSubscription(User $user): ?Subscription
    {
        return $user->getCompany()?->getSubscription();
    }
}
