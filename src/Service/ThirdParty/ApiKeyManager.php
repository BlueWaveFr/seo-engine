<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;

class ApiKeyManager
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function getApiKey(string $provider): ?ApiKey
    {
        $apiKey = $this->entityManager->getRepository(ApiKey::class)->findOneBy([
            'provider' => $provider,
            'isActive' => true,
        ]);

        if ($apiKey) {
            $apiKey->markAsUsed();
            $this->entityManager->flush();
        }

        return $apiKey;
    }

    public function getCredentials(string $provider): ?array
    {
        $apiKey = $this->getApiKey($provider);

        if (!$apiKey) {
            return null;
        }

        return [
            'apiKey' => $apiKey->getApiKey(),
            'apiSecret' => $apiKey->getApiSecret(),
            'accessToken' => $apiKey->getAccessToken(),
            'refreshToken' => $apiKey->getRefreshToken(),
            'additionalConfig' => $apiKey->getAdditionalConfig(),
        ];
    }

    public function isConfigured(string $provider): bool
    {
        return $this->getApiKey($provider) !== null;
    }
}
