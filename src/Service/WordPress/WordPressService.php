<?php

namespace SeoExpert\Engine\Service\WordPress;

use SeoExpert\Engine\Entity\Content;
use SeoExpert\Engine\Entity\Project;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WordPressService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Test connection to WordPress site
     */
    public function testConnection(string $siteUrl, string $username, string $applicationPassword): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);
        $authHeader = $this->buildAuthHeader($username, $applicationPassword);

        try {
            // First, check if the REST API is accessible
            $response = $this->httpClient->request('GET', $apiUrl . '/users/me', [
                'headers' => [
                    'Authorization' => $authHeader,
                ],
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $userData = $response->toArray();
                return [
                    'success' => true,
                    'user' => [
                        'id' => $userData['id'],
                        'name' => $userData['name'],
                        'slug' => $userData['slug'],
                        'roles' => $userData['roles'] ?? [],
                    ],
                ];
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Identifiants incorrects. Verifiez le nom d\'utilisateur (login WordPress, pas l\'email) et le mot de passe d\'application.',
                ];
            }

            if ($statusCode === 403) {
                return [
                    'success' => false,
                    'error' => 'Acces refuse. L\'utilisateur n\'a pas les permissions necessaires.',
                ];
            }

            return [
                'success' => false,
                'error' => 'Reponse inattendue: HTTP ' . $statusCode,
            ];
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $this->logger->error('WordPress connection test failed (transport): ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Impossible de se connecter au site. Verifiez l\'URL et que le site est accessible.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress connection test failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build Authorization header for WordPress Application Passwords
     */
    private function buildAuthHeader(string $username, string $applicationPassword): string
    {
        // WordPress application passwords can have spaces - keep them
        // But trim leading/trailing whitespace that might be added by copy-paste
        $username = trim($username);
        $applicationPassword = trim($applicationPassword);

        return 'Basic ' . base64_encode($username . ':' . $applicationPassword);
    }

    /**
     * Get available post types (including custom post types)
     */
    public function getPostTypes(string $siteUrl, string $username, string $applicationPassword): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl . '/types', [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $types = $response->toArray();
                $postTypes = [];

                foreach ($types as $slug => $type) {
                    // Filter viewable and REST-enabled types
                    if (isset($type['rest_base']) && !empty($type['rest_base'])) {
                        $postTypes[] = [
                            'slug' => $slug,
                            'name' => $type['name'] ?? $slug,
                            'description' => $type['description'] ?? '',
                            'rest_base' => $type['rest_base'],
                            'hierarchical' => $type['hierarchical'] ?? false,
                            'supports' => $type['supports'] ?? [],
                        ];
                    }
                }

                return [
                    'success' => true,
                    'post_types' => $postTypes,
                ];
            }

            return [
                'success' => false,
                'error' => 'Could not fetch post types',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get post types failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get categories for a post type
     */
    public function getCategories(string $siteUrl, string $username, string $applicationPassword, string $taxonomy = 'categories'): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl . '/' . $taxonomy, [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'query' => [
                    'per_page' => 100,
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $categories = $response->toArray();
                return [
                    'success' => true,
                    'categories' => array_map(fn($cat) => [
                        'id' => $cat['id'],
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                        'parent' => $cat['parent'] ?? 0,
                        'count' => $cat['count'] ?? 0,
                    ], $categories),
                ];
            }

            return [
                'success' => false,
                'error' => 'Could not fetch categories',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get categories failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get tags
     */
    public function getTags(string $siteUrl, string $username, string $applicationPassword): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl . '/tags', [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'query' => [
                    'per_page' => 100,
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $tags = $response->toArray();
                return [
                    'success' => true,
                    'tags' => array_map(fn($tag) => [
                        'id' => $tag['id'],
                        'name' => $tag['name'],
                        'slug' => $tag['slug'],
                        'count' => $tag['count'] ?? 0,
                    ], $tags),
                ];
            }

            return [
                'success' => false,
                'error' => 'Could not fetch tags',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get tags failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get custom taxonomies for a post type
     */
    public function getTaxonomies(string $siteUrl, string $username, string $applicationPassword, ?string $postType = null): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $query = [];
            if ($postType) {
                $query['type'] = $postType;
            }

            $response = $this->httpClient->request('GET', $apiUrl . '/taxonomies', [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'query' => $query,
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $taxonomies = $response->toArray();
                return [
                    'success' => true,
                    'taxonomies' => array_map(fn($tax) => [
                        'slug' => $tax['slug'] ?? '',
                        'name' => $tax['name'] ?? '',
                        'rest_base' => $tax['rest_base'] ?? '',
                        'hierarchical' => $tax['hierarchical'] ?? false,
                    ], $taxonomies),
                ];
            }

            return [
                'success' => false,
                'error' => 'Could not fetch taxonomies',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get taxonomies failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get media library items
     */
    public function getMedia(string $siteUrl, string $username, string $applicationPassword, int $page = 1, int $perPage = 20): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl . '/media', [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'query' => [
                    'page' => $page,
                    'per_page' => $perPage,
                ],
                'timeout' => 15,
            ]);

            if ($response->getStatusCode() === 200) {
                $media = $response->toArray();
                $totalPages = (int) ($response->getHeaders()['x-wp-totalpages'][0] ?? 1);
                $total = (int) ($response->getHeaders()['x-wp-total'][0] ?? count($media));

                return [
                    'success' => true,
                    'media' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => $item['title']['rendered'] ?? '',
                        'url' => $item['source_url'] ?? '',
                        'thumbnail' => $item['media_details']['sizes']['thumbnail']['source_url'] ?? $item['source_url'],
                        'mime_type' => $item['mime_type'] ?? '',
                        'alt_text' => $item['alt_text'] ?? '',
                    ], $media),
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'current_page' => $page,
                ];
            }

            return [
                'success' => false,
                'error' => 'Could not fetch media',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get media failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload media to WordPress
     */
    public function uploadMedia(string $siteUrl, string $username, string $applicationPassword, string $filePath, ?string $title = null, ?string $altText = null): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'File not found: ' . $filePath,
            ];
        }

        $fileName = basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        try {
            $response = $this->httpClient->request('POST', $apiUrl . '/media', [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'Content-Type' => $mimeType,
                ],
                'body' => file_get_contents($filePath),
                'timeout' => 60,
            ]);

            if ($response->getStatusCode() === 201) {
                $media = $response->toArray();

                // Update title and alt text if provided
                if ($title || $altText) {
                    $updateData = [];
                    if ($title) $updateData['title'] = $title;
                    if ($altText) $updateData['alt_text'] = $altText;

                    $this->httpClient->request('POST', $apiUrl . '/media/' . $media['id'], [
                        'headers' => [
                            'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $updateData,
                    ]);
                }

                return [
                    'success' => true,
                    'media' => [
                        'id' => $media['id'],
                        'url' => $media['source_url'] ?? '',
                        'title' => $media['title']['rendered'] ?? $title ?? $fileName,
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Upload failed: ' . $response->getStatusCode(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress upload media failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create or update a post/page in WordPress
     */
    public function publishContent(
        string $siteUrl,
        string $username,
        string $applicationPassword,
        Content $content,
        string $postType = 'posts',
        string $status = 'draft',
        ?array $categories = null,
        ?array $tags = null,
        ?int $featuredMediaId = null,
        ?int $existingPostId = null
    ): array {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        // Build post data
        $postData = [
            'title' => $content->getTitle(),
            'content' => $content->getBody() ?? '',
            'status' => $status,
        ];

        // Add excerpt if available
        if ($content->getMetaDescription()) {
            $postData['excerpt'] = $content->getMetaDescription();
        }

        // Add slug if available
        if ($content->getSlug()) {
            $postData['slug'] = $content->getSlug();
        }

        // Add categories (for posts)
        if ($categories && $postType === 'posts') {
            $postData['categories'] = $categories;
        }

        // Add tags (for posts)
        if ($tags && $postType === 'posts') {
            $postData['tags'] = $tags;
        }

        // Add featured image
        if ($featuredMediaId) {
            $postData['featured_media'] = $featuredMediaId;
        }

        // Add SEO meta fields if Yoast or RankMath is installed
        $meta = [];
        if ($content->getMetaDescription()) {
            $meta['_yoast_wpseo_metadesc'] = $content->getMetaDescription();
            $meta['rank_math_description'] = $content->getMetaDescription();
        }
        if ($content->getTargetKeyword()) {
            $meta['_yoast_wpseo_focuskw'] = $content->getTargetKeyword();
            $meta['rank_math_focus_keyword'] = $content->getTargetKeyword();
        }
        if (!empty($meta)) {
            $postData['meta'] = $meta;
        }

        try {
            $endpoint = $apiUrl . '/' . $postType;
            $method = 'POST';

            if ($existingPostId) {
                $endpoint .= '/' . $existingPostId;
            }

            $response = $this->httpClient->request($method, $endpoint, [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                    'Content-Type' => 'application/json',
                ],
                'json' => $postData,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 201 || $statusCode === 200) {
                $post = $response->toArray();
                return [
                    'success' => true,
                    'post' => [
                        'id' => $post['id'],
                        'title' => $post['title']['rendered'] ?? '',
                        'slug' => $post['slug'] ?? '',
                        'link' => $post['link'] ?? '',
                        'status' => $post['status'] ?? $status,
                        'edit_link' => $siteUrl . '/wp-admin/post.php?post=' . $post['id'] . '&action=edit',
                    ],
                ];
            }

            $errorBody = $response->getContent(false);
            return [
                'success' => false,
                'error' => 'Publish failed: HTTP ' . $statusCode . ' - ' . $errorBody,
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress publish failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get a specific post by ID
     */
    public function getPost(string $siteUrl, string $username, string $applicationPassword, int $postId, string $postType = 'posts'): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl . '/' . $postType . '/' . $postId, [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $post = $response->toArray();
                return [
                    'success' => true,
                    'post' => [
                        'id' => $post['id'],
                        'title' => $post['title']['rendered'] ?? '',
                        'content' => $post['content']['rendered'] ?? '',
                        'excerpt' => $post['excerpt']['rendered'] ?? '',
                        'slug' => $post['slug'] ?? '',
                        'link' => $post['link'] ?? '',
                        'status' => $post['status'] ?? '',
                        'date' => $post['date'] ?? '',
                        'modified' => $post['modified'] ?? '',
                        'categories' => $post['categories'] ?? [],
                        'tags' => $post['tags'] ?? [],
                        'featured_media' => $post['featured_media'] ?? null,
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Post not found',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get post failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a post
     */
    public function deletePost(string $siteUrl, string $username, string $applicationPassword, int $postId, string $postType = 'posts', bool $force = false): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('DELETE', $apiUrl . '/' . $postType . '/' . $postId, [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'query' => [
                    'force' => $force ? 'true' : 'false',
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                return [
                    'success' => true,
                    'message' => 'Post deleted successfully',
                ];
            }

            return [
                'success' => false,
                'error' => 'Delete failed: ' . $response->getStatusCode(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress delete post failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get site info
     */
    public function getSiteInfo(string $siteUrl): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl, [
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                return [
                    'success' => true,
                    'site' => [
                        'name' => $data['name'] ?? '',
                        'description' => $data['description'] ?? '',
                        'url' => $data['url'] ?? $siteUrl,
                        'home' => $data['home'] ?? $siteUrl,
                        'gmt_offset' => $data['gmt_offset'] ?? 0,
                        'timezone_string' => $data['timezone_string'] ?? '',
                        'namespaces' => $data['namespaces'] ?? [],
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Could not fetch site info',
            ];
        } catch (\Exception $e) {
            $this->logger->error('WordPress get site info failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if WaveRank plugin is installed
     */
    public function checkWaveRankPlugin(string $siteUrl, string $username, string $applicationPassword): array
    {
        $apiUrl = $this->getRestApiUrl($siteUrl);

        try {
            $response = $this->httpClient->request('GET', $apiUrl . '/waverank/v1/status', [
                'headers' => [
                    'Authorization' => $this->buildAuthHeader($username, $applicationPassword),
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                return [
                    'success' => true,
                    'installed' => true,
                    'version' => $data['version'] ?? 'unknown',
                    'features' => $data['features'] ?? [],
                ];
            }

            return [
                'success' => true,
                'installed' => false,
            ];
        } catch (\Exception $e) {
            // 404 means plugin is not installed
            return [
                'success' => true,
                'installed' => false,
            ];
        }
    }

    /**
     * Normalize WordPress REST API URL
     */
    private function getRestApiUrl(string $siteUrl): string
    {
        $siteUrl = rtrim($siteUrl, '/');

        // If already contains wp-json, use as-is
        if (str_contains($siteUrl, 'wp-json')) {
            return $siteUrl;
        }

        return $siteUrl . '/wp-json/wp/v2';
    }
}
