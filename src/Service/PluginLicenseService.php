<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\Company;
use SeoExpert\Engine\Entity\PluginLicense;
use Doctrine\ORM\EntityManagerInterface;

class PluginLicenseService
{
    private array $tierConfig;
    private bool $freeTierEnabled;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        array $pluginTiers = [],
        bool $pluginFreeTierEnabled = true,
    ) {
        $this->tierConfig = $pluginTiers;
        $this->freeTierEnabled = $pluginFreeTierEnabled;
    }

    public function generateLicenseKey(): string
    {
        return bin2hex(random_bytes(16)); // 32 chars hex
    }

    public function createLicense(Company $company, string $tier, string $cms): PluginLicense
    {
        if ($tier === PluginLicense::TIER_FREE && !$this->freeTierEnabled) {
            throw new \RuntimeException('Le palier gratuit est actuellement désactivé.');
        }

        $license = new PluginLicense();
        $license->setCompany($company);
        $license->setLicenseKey($this->generateLicenseKey());
        $license->setTier($tier);
        $license->setCms($cms);

        $this->applyTierDefaults($license);

        $this->entityManager->persist($license);
        $this->entityManager->flush();

        return $license;
    }

    public function validateLicense(string $licenseKey, string $domain, string $cms): array
    {
        $license = $this->findByKey($licenseKey);

        if (!$license) {
            return ['valid' => false, 'error' => 'Clé de licence invalide.'];
        }

        if (!$license->isActive()) {
            return ['valid' => false, 'error' => 'Licence désactivée.'];
        }

        if ($license->isExpired()) {
            return ['valid' => false, 'error' => 'Licence expirée.'];
        }

        if ($license->getCms() !== $cms) {
            return ['valid' => false, 'error' => sprintf('Cette licence est pour %s, pas %s.', $license->getCms(), $cms)];
        }

        if ($license->getTier() === PluginLicense::TIER_FREE && !$this->freeTierEnabled) {
            return ['valid' => false, 'error' => 'Le palier gratuit est actuellement désactivé.'];
        }

        $domainMatch = true;
        if ($license->isDomainActivated()) {
            $domainMatch = $license->isDomainMatch($domain);
        }

        return [
            'valid' => true,
            'tier' => $license->getTier(),
            'features' => $this->getFeatures($license->getTier()),
            'quotas' => [
                'publish_limit' => $license->getMaxPublishPerMonth(),
                'publish_used' => $license->getPublishUsedThisMonth(),
                'publish_remaining' => $license->getPublishRemaining(),
            ],
            'domain_match' => $domainMatch,
            'activated_domain' => $license->getActivatedDomain(),
            'free_tier_enabled' => $this->freeTierEnabled,
            'audit_enabled' => $license->isAuditEnabled(),
            'expires_at' => $license->getExpiresAt()?->format('c'),
        ];
    }

    public function activateDomain(string $licenseKey, string $domain, string $cms): array
    {
        $license = $this->findByKey($licenseKey);

        if (!$license) {
            return ['success' => false, 'error' => 'Clé de licence invalide.'];
        }

        if (!$license->hasValidAccess()) {
            return ['success' => false, 'error' => 'Licence inactive ou expirée.'];
        }

        if ($license->getCms() !== $cms) {
            return ['success' => false, 'error' => sprintf('Cette licence est pour %s.', $license->getCms())];
        }

        // Check if domain already activated on another domain (Pro = 1 domain max)
        if ($license->isDomainActivated() && !$license->isDomainMatch($domain)) {
            if ($license->getTier() === PluginLicense::TIER_PRO) {
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Cette licence Pro est déjà activée sur %s. Désactivez-la depuis WaveRank avant de l\'activer sur un autre domaine.',
                        $license->getActivatedDomain()
                    ),
                ];
            }
            // Agency can have multiple, but this single license is already bound
            // In practice, agency gets multiple licenses
        }

        $license->setActivatedDomain($domain);
        $license->setActivatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return [
            'success' => true,
            'domain' => $domain,
            'tier' => $license->getTier(),
            'features' => $this->getFeatures($license->getTier()),
        ];
    }

    public function deactivateDomain(string $licenseKey): array
    {
        $license = $this->findByKey($licenseKey);

        if (!$license) {
            return ['success' => false, 'error' => 'Clé de licence invalide.'];
        }

        $previousDomain = $license->getActivatedDomain();
        $license->setActivatedDomain(null);
        $license->setActivatedAt(null);
        $this->entityManager->flush();

        return [
            'success' => true,
            'previous_domain' => $previousDomain,
        ];
    }

    public function checkAndIncrementPublish(string $licenseKey, int $count = 1): array
    {
        $license = $this->findByKey($licenseKey);

        if (!$license || !$license->hasValidAccess()) {
            return ['allowed' => false, 'error' => 'Licence invalide ou inactive.'];
        }

        // Check monthly reset
        $this->checkMonthlyReset($license);

        if ($license->getMaxPublishPerMonth() !== -1) {
            $remaining = $license->getPublishRemaining();
            if ($remaining < $count) {
                return [
                    'allowed' => false,
                    'error' => sprintf('Quota de publications atteint (%d/%d ce mois).', $license->getPublishUsedThisMonth(), $license->getMaxPublishPerMonth()),
                    'quotas' => [
                        'publish_limit' => $license->getMaxPublishPerMonth(),
                        'publish_used' => $license->getPublishUsedThisMonth(),
                        'publish_remaining' => $remaining,
                    ],
                ];
            }
        }

        $license->incrementPublishUsed($count);
        $this->entityManager->flush();

        return [
            'allowed' => true,
            'quotas' => [
                'publish_limit' => $license->getMaxPublishPerMonth(),
                'publish_used' => $license->getPublishUsedThisMonth(),
                'publish_remaining' => $license->getPublishRemaining(),
            ],
        ];
    }

    public function heartbeat(string $licenseKey, string $domain, string $cms): array
    {
        $license = $this->findByKey($licenseKey);

        if (!$license) {
            return ['valid' => false, 'error' => 'Clé de licence invalide.'];
        }

        $this->checkMonthlyReset($license);

        return [
            'valid' => $license->hasValidAccess(),
            'tier' => $license->getTier(),
            'features' => $this->getFeatures($license->getTier()),
            'quotas' => [
                'publish_limit' => $license->getMaxPublishPerMonth(),
                'publish_used' => $license->getPublishUsedThisMonth(),
                'publish_remaining' => $license->getPublishRemaining(),
            ],
            'domain_match' => $license->isDomainActivated() ? $license->isDomainMatch($domain) : null,
            'free_tier_enabled' => $this->freeTierEnabled,
            'audit_enabled' => $license->isAuditEnabled(),
        ];
    }

    public function getFeatures(string $tier): array
    {
        $config = $this->tierConfig[$tier] ?? [];

        return [
            'max_publish_per_month' => $config['max_publish_per_month'] ?? 5,
            'content_types' => $config['content_types'] ?? 1,
            'seo_sync' => $config['seo_sync'] ?? 'basic',
            'audit' => $config['audit'] ?? false,
            'bulk_publish' => $config['bulk_publish'] ?? false,
            'webhooks' => $config['webhooks'] ?? false,
            'max_domains' => $config['max_domains'] ?? 1,
            'white_label' => $config['white_label'] ?? false,
        ];
    }

    public function isFreeTierEnabled(): bool
    {
        return $this->freeTierEnabled;
    }

    public function findByKey(string $licenseKey): ?PluginLicense
    {
        return $this->entityManager->getRepository(PluginLicense::class)
            ->findOneBy(['licenseKey' => $licenseKey]);
    }

    public function findByCompany(Company $company): array
    {
        return $this->entityManager->getRepository(PluginLicense::class)
            ->findBy(['company' => $company], ['createdAt' => 'DESC']);
    }

    private function applyTierDefaults(PluginLicense $license): void
    {
        $features = $this->getFeatures($license->getTier());

        $license->setMaxPublishPerMonth($features['max_publish_per_month']);
        $license->setAuditEnabled($features['audit']);

        // Pro/Agency don't expire by default (managed by subscription)
        if ($license->getTier() === PluginLicense::TIER_FREE) {
            // Free has no expiration
            $license->setExpiresAt(null);
        }
    }

    private function checkMonthlyReset(PluginLicense $license): void
    {
        $now = new \DateTimeImmutable();
        if ($now > $license->getCurrentPeriodEnd()) {
            $license->resetMonthlyUsage();
            $license->setCurrentPeriodStart($now);
            $license->setCurrentPeriodEnd($now->modify('+1 month'));
            $this->entityManager->flush();
        }
    }
}
