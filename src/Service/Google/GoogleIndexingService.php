<?php

namespace SeoExpert\Engine\Service\Google;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client as GoogleClient;
use Google\Service\Indexing;
use Google\Service\Indexing\UrlNotification;
use Psr\Log\LoggerInterface;

class GoogleIndexingService
{
    private ?GoogleClient $client = null;
    private bool $isConfigured = false;

    private ?string $initError = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        try {
            $this->initializeClient();
        } catch (\Throwable $e) {
            $this->initError = $e->getMessage();
            $this->logger->error('GoogleIndexingService init error: ' . $e->getMessage());
        }
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    private function initializeClient(): void
    {
        // Get service account credentials from database
        $apiKeyRepo = $this->entityManager->getRepository(ApiKey::class);
        $indexingKey = $apiKeyRepo->findOneBy(['provider' => 'google_indexing', 'isActive' => true]);

        if (!$indexingKey) {
            $this->logger->info('Google Indexing API not configured');
            return;
        }

        $serviceAccountJson = $indexingKey->getAdditionalConfig();

        if (!$serviceAccountJson || !isset($serviceAccountJson['type']) || $serviceAccountJson['type'] !== 'service_account') {
            $this->logger->warning('Invalid Google Indexing service account configuration');
            return;
        }

        try {
            $this->client = new GoogleClient();
            $this->client->setAuthConfig($serviceAccountJson);
            $this->client->addScope(Indexing::INDEXING);
            $this->isConfigured = true;
            $this->logger->info('Google Indexing API configured successfully');
        } catch (\Exception $e) {
            $this->logger->error('Failed to configure Google Indexing API: ' . $e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    /**
     * Submit URL for indexing (URL_UPDATED)
     */
    public function submitUrl(string $url): array
    {
        return $this->notifyUrl($url, 'URL_UPDATED');
    }

    /**
     * Request URL removal (URL_DELETED)
     */
    public function removeUrl(string $url): array
    {
        return $this->notifyUrl($url, 'URL_DELETED');
    }

    /**
     * Submit multiple URLs for indexing
     */
    public function submitUrls(array $urls): array
    {
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = $this->submitUrl($url);
            // Add small delay to avoid rate limiting
            usleep(100000); // 100ms
        }
        return $results;
    }

    /**
     * Get indexing status for a URL
     */
    public function getUrlStatus(string $url): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Google Indexing API not configured',
            ];
        }

        try {
            $indexing = new Indexing($this->client);
            $metadata = $indexing->urlNotifications->getMetadata(['url' => $url]);

            return [
                'success' => true,
                'url' => $metadata->getUrl(),
                'latestUpdate' => $metadata->getLatestUpdate() ? [
                    'url' => $metadata->getLatestUpdate()->getUrl(),
                    'type' => $metadata->getLatestUpdate()->getType(),
                    'notifyTime' => $metadata->getLatestUpdate()->getNotifyTime(),
                ] : null,
                'latestRemove' => $metadata->getLatestRemove() ? [
                    'url' => $metadata->getLatestRemove()->getUrl(),
                    'type' => $metadata->getLatestRemove()->getType(),
                    'notifyTime' => $metadata->getLatestRemove()->getNotifyTime(),
                ] : null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get URL status: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function notifyUrl(string $url, string $type): array
    {
        if (!$this->isConfigured) {
            return [
                'success' => false,
                'error' => 'Google Indexing API not configured. Please add a service account JSON in Admin > API Keys > Google Indexing.',
            ];
        }

        try {
            $indexing = new Indexing($this->client);

            $notification = new UrlNotification();
            $notification->setUrl($url);
            $notification->setType($type);

            $response = $indexing->urlNotifications->publish($notification);

            $this->logger->info("URL submitted to Google: {$url} ({$type})");

            return [
                'success' => true,
                'url' => $response->getUrlNotificationMetadata()->getUrl(),
                'type' => $type,
                'notifyTime' => $response->getUrlNotificationMetadata()->getLatestUpdate()?->getNotifyTime(),
            ];
        } catch (\Google\Service\Exception $e) {
            $error = json_decode($e->getMessage(), true);
            $errorMessage = $error['error']['message'] ?? $e->getMessage();

            $this->logger->error("Failed to submit URL to Google: {$errorMessage}");

            return [
                'success' => false,
                'url' => $url,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to submit URL to Google: " . $e->getMessage());

            return [
                'success' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }
}
