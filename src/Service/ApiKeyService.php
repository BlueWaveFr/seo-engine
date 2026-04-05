<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;

class ApiKeyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $defaultGoogleClientId,
        private readonly string $defaultGoogleClientSecret,
        private readonly string $defaultGoogleRedirectUri,
        private readonly string $linkedinClientId = '',
        private readonly string $linkedinClientSecret = '',
        private readonly string $xClientId = '',
        private readonly string $xClientSecret = '',
    ) {}

    public function getApiKey(string $provider): ?ApiKey
    {
        return $this->entityManager->getRepository(ApiKey::class)
            ->findOneBy(['provider' => $provider, 'isActive' => true]);
    }

    public function getGoogleOAuthCredentials(): array
    {
        $apiKey = $this->getApiKey(ApiKey::PROVIDER_GOOGLE_OAUTH);

        if ($apiKey && $apiKey->getApiKey() && $apiKey->getApiSecret()) {
            $config = $apiKey->getAdditionalConfig() ?? [];
            return [
                'client_id' => $apiKey->getApiKey(),
                'client_secret' => $apiKey->getApiSecret(),
                'redirect_uri' => $config['redirect_uri'] ?? $this->defaultGoogleRedirectUri,
            ];
        }

        // Fallback to environment variables
        return [
            'client_id' => $this->defaultGoogleClientId,
            'client_secret' => $this->defaultGoogleClientSecret,
            'redirect_uri' => $this->defaultGoogleRedirectUri,
        ];
    }

    public function markAsUsed(string $provider): void
    {
        $apiKey = $this->getApiKey($provider);
        if ($apiKey) {
            $apiKey->markAsUsed();
            $this->entityManager->flush();
        }
    }

    public function getLinkedInOAuthCredentials(): array
    {
        $apiKey = $this->getApiKey(ApiKey::PROVIDER_LINKEDIN);

        if ($apiKey && $apiKey->getApiKey() && $apiKey->getApiSecret()) {
            return [
                'client_id' => $apiKey->getApiKey(),
                'client_secret' => $apiKey->getApiSecret(),
            ];
        }

        return [
            'client_id' => $this->linkedinClientId,
            'client_secret' => $this->linkedinClientSecret,
        ];
    }

    public function getXOAuthCredentials(): array
    {
        $apiKey = $this->getApiKey(ApiKey::PROVIDER_X);

        if ($apiKey && $apiKey->getApiKey() && $apiKey->getApiSecret()) {
            return [
                'client_id' => $apiKey->getApiKey(),
                'client_secret' => $apiKey->getApiSecret(),
            ];
        }

        return [
            'client_id' => $this->xClientId,
            'client_secret' => $this->xClientSecret,
        ];
    }
}
