<?php

namespace SeoExpert\Engine\Service\ThirdParty;

use SeoExpert\Engine\Entity\BlogArticle;
use SeoExpert\Engine\Entity\SocialAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SocialPublishingService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private string $siteUrl = 'https://waverank.io'
    ) {}

    public function publishArticle(BlogArticle $article, array $platforms = []): array
    {
        $results = [];

        $accounts = $this->entityManager->getRepository(SocialAccount::class)->findBy([
            'isActive' => true,
        ]);

        foreach ($accounts as $account) {
            if (!empty($platforms) && !in_array($account->getPlatform(), $platforms)) {
                continue;
            }

            if (!$account->hasValidToken()) {
                $results[$account->getPlatform()] = [
                    'success' => false,
                    'error' => 'Token invalide ou expiré',
                ];
                continue;
            }

            try {
                $result = match ($account->getPlatform()) {
                    SocialAccount::PLATFORM_X => $this->publishToX($article, $account),
                    SocialAccount::PLATFORM_LINKEDIN => $this->publishToLinkedIn($article, $account),
                    default => ['success' => false, 'error' => 'Plateforme non supportée'],
                };

                $results[$account->getPlatform()] = $result;
            } catch (\Exception $e) {
                $results[$account->getPlatform()] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->updateArticleSocialShares($article, $results);

        return $results;
    }

    private function publishToX(BlogArticle $article, SocialAccount $account): array
    {
        $text = $this->formatTwitterText($article);
        $url = $this->siteUrl . '/blog/' . $article->getSlug();

        $response = $this->httpClient->request('POST', 'https://api.twitter.com/2/tweets', [
            'headers' => [
                'Authorization' => 'Bearer ' . $account->getAccessToken(),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'text' => $text . "\n\n" . $url,
            ],
        ]);

        if ($response->getStatusCode() === 201) {
            $data = $response->toArray();
            return [
                'success' => true,
                'postId' => $data['data']['id'] ?? null,
                'url' => 'https://x.com/i/web/status/' . ($data['data']['id'] ?? ''),
            ];
        }

        return [
            'success' => false,
            'error' => 'Erreur lors de la publication',
        ];
    }

    private function publishToLinkedIn(BlogArticle $article, SocialAccount $account): array
    {
        $config = json_decode($account->getApiSecret() ?? '{}', true);
        $personUrn = $config['personUrn'] ?? null;

        if (!$personUrn) {
            return ['success' => false, 'error' => 'URN de profil LinkedIn manquant'];
        }

        $url = $this->siteUrl . '/blog/' . $article->getSlug();

        $postData = [
            'author' => $personUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $this->formatLinkedInText($article),
                    ],
                    'shareMediaCategory' => 'ARTICLE',
                    'media' => [
                        [
                            'status' => 'READY',
                            'originalUrl' => $url,
                            'title' => [
                                'text' => $article->getTitle(),
                            ],
                            'description' => [
                                'text' => $article->getExcerpt() ?? '',
                            ],
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = $this->httpClient->request('POST', 'https://api.linkedin.com/v2/ugcPosts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $account->getAccessToken(),
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'json' => $postData,
        ]);

        if ($response->getStatusCode() === 201) {
            $data = $response->toArray();
            $postId = $data['id'] ?? null;

            return [
                'success' => true,
                'postId' => $postId,
                'url' => $postId ? 'https://www.linkedin.com/feed/update/' . $postId : null,
            ];
        }

        return [
            'success' => false,
            'error' => 'Erreur lors de la publication',
        ];
    }

    private function formatTwitterText(BlogArticle $article): string
    {
        $text = $article->getTitle();

        if (strlen($text) > 200 && $article->getExcerpt()) {
            $text .= "\n\n" . substr($article->getExcerpt(), 0, 100) . '...';
        }

        $hashtags = $this->generateHashtags($article);
        if ($hashtags) {
            $text .= "\n\n" . $hashtags;
        }

        return substr($text, 0, 250);
    }

    private function formatLinkedInText(BlogArticle $article): string
    {
        $text = $article->getTitle();

        if ($article->getExcerpt()) {
            $text .= "\n\n" . $article->getExcerpt();
        }

        $hashtags = $this->generateHashtags($article);
        if ($hashtags) {
            $text .= "\n\n" . $hashtags;
        }

        return $text;
    }

    private function generateHashtags(BlogArticle $article): string
    {
        $hashtags = ['#SEO', '#Marketing'];

        $categoryName = $article->getCategory()->getName();
        $hashtags[] = '#' . preg_replace('/[^a-zA-Z0-9]/', '', $categoryName);

        if ($article->getMetaKeywords()) {
            foreach (array_slice($article->getMetaKeywords(), 0, 2) as $keyword) {
                $hashtags[] = '#' . preg_replace('/[^a-zA-Z0-9]/', '', $keyword);
            }
        }

        return implode(' ', array_unique($hashtags));
    }

    private function updateArticleSocialShares(BlogArticle $article, array $results): void
    {
        $shares = $article->getSocialShares() ?? [];

        foreach ($results as $platform => $result) {
            if ($result['success']) {
                $shares[$platform] = [
                    'publishedAt' => (new \DateTimeImmutable())->format('c'),
                    'postId' => $result['postId'] ?? null,
                    'url' => $result['url'] ?? null,
                ];
            }
        }

        $article->setSocialShares($shares);
        $this->entityManager->flush();
    }

    public function getAvailablePlatforms(): array
    {
        $accounts = $this->entityManager->getRepository(SocialAccount::class)->findBy([
            'isActive' => true,
        ]);

        $platforms = [];
        foreach ($accounts as $account) {
            $platforms[] = [
                'platform' => $account->getPlatform(),
                'name' => $account->getPlatformName(),
                'hasValidToken' => $account->hasValidToken(),
                'autoPublish' => $account->isAutoPublish(),
            ];
        }

        return $platforms;
    }
}
