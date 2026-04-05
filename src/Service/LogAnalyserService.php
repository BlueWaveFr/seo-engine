<?php

namespace SeoExpert\Engine\Service;

use Psr\Log\LoggerInterface;

class LogAnalyserService
{
    // Known bot user agents
    private const BOTS = [
        'Googlebot' => ['googlebot', 'google-inspectiontool', 'googleother', 'google-extended', 'storebot-google', 'apis-google'],
        'Bingbot' => ['bingbot', 'msnbot', 'bingpreview'],
        'YandexBot' => ['yandexbot', 'yandexmobilebot'],
        'Baidu' => ['baiduspider'],
        'DuckDuckBot' => ['duckduckbot'],
        'Facebot' => ['facebot', 'facebookexternalhit'],
        'Twitterbot' => ['twitterbot'],
        'LinkedInBot' => ['linkedinbot'],
        'Applebot' => ['applebot'],
        'AhrefsBot' => ['ahrefsbot'],
        'SemrushBot' => ['semrushbot'],
        'MJ12bot' => ['mj12bot'],
        'DotBot' => ['dotbot'],
        'PetalBot' => ['petalbot'],
        'Bytespider' => ['bytespider', 'bytedance'],
        'GPTBot' => ['gptbot'],
        'ClaudeBot' => ['claude-web', 'claudebot'],
        'ChatGPT-User' => ['chatgpt-user'],
    ];

    // Resource file extensions (crawl budget waste)
    private const RESOURCE_EXTENSIONS = [
        'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'map', 'json',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function analyze(string $content, int $maxSizeBytes = 2_097_152): array
    {
        if (strlen($content) > $maxSizeBytes) {
            throw new \RuntimeException('File too large. Maximum ' . round($maxSizeBytes / 1048576) . ' MB allowed.');
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        $stats = [
            'totalRequests' => 0,
            'botRequests' => 0,
            'humanRequests' => 0,
            'statusCodes' => [],
            'bots' => [],
            'topPages' => [],
            'topBotPages' => [],
            'resourcesCrawled' => 0,
            'errors4xx' => [],
            'errors5xx' => [],
            'crawlBudgetWaste' => [],
            'requestsByHour' => array_fill(0, 24, 0),
            'methods' => [],
        ];

        $pageHits = [];
        $botPageHits = [];
        $errorPages4xx = [];
        $errorPages5xx = [];
        $resourceHits = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parsed = $this->parseLine($line);
            if (!$parsed) continue;

            $stats['totalRequests']++;

            // Status codes
            $code = $parsed['status'];
            $stats['statusCodes'][$code] = ($stats['statusCodes'][$code] ?? 0) + 1;

            // Methods
            $method = $parsed['method'];
            $stats['methods'][$method] = ($stats['methods'][$method] ?? 0) + 1;

            // Hour distribution
            if ($parsed['hour'] !== null && $parsed['hour'] >= 0 && $parsed['hour'] <= 23) {
                $stats['requestsByHour'][$parsed['hour']]++;
            }

            // Page hits
            $path = $parsed['path'];
            $pageHits[$path] = ($pageHits[$path] ?? 0) + 1;

            // Error tracking
            if ($code >= 400 && $code < 500) {
                $errorPages4xx[$path] = ($errorPages4xx[$path] ?? 0) + 1;
            } elseif ($code >= 500) {
                $errorPages5xx[$path] = ($errorPages5xx[$path] ?? 0) + 1;
            }

            // Bot detection
            $botName = $this->detectBot($parsed['userAgent']);
            if ($botName) {
                $stats['botRequests']++;
                $stats['bots'][$botName] = ($stats['bots'][$botName] ?? 0) + 1;
                $botPageHits[$path] = ($botPageHits[$path] ?? 0) + 1;

                // Resource crawl (budget waste)
                $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));
                if (in_array($ext, self::RESOURCE_EXTENSIONS)) {
                    $stats['resourcesCrawled']++;
                    $resourceHits[$ext] = ($resourceHits[$ext] ?? 0) + 1;
                }
            } else {
                $stats['humanRequests']++;
            }
        }

        // Sort and limit results
        arsort($pageHits);
        arsort($botPageHits);
        arsort($errorPages4xx);
        arsort($errorPages5xx);
        arsort($stats['bots']);
        arsort($stats['statusCodes']);
        arsort($resourceHits);

        $stats['topPages'] = array_slice($pageHits, 0, 20, true);
        $stats['topBotPages'] = array_slice($botPageHits, 0, 20, true);
        $stats['errors4xx'] = array_slice($errorPages4xx, 0, 15, true);
        $stats['errors5xx'] = array_slice($errorPages5xx, 0, 15, true);
        $stats['crawlBudgetWaste'] = $resourceHits;

        // Generate recommendations
        $stats['recommendations'] = $this->generateRecommendations($stats);
        $stats['parsedLines'] = $stats['totalRequests'];
        $stats['totalLines'] = $totalLines;

        return $stats;
    }

    private function parseLine(string $line): ?array
    {
        // Combined Log Format: IP - - [datetime] "METHOD /path HTTP/x.x" status size "referer" "user-agent"
        $pattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"(\S+)\s+(\S+)\s+\S+"\s+(\d{3})\s+\S+\s+"[^"]*"\s+"([^"]*)"/';

        if (preg_match($pattern, $line, $matches)) {
            $hour = null;
            if (preg_match('/\d{4}:(\d{2}):\d{2}:\d{2}/', $matches[2], $timeMatch)) {
                $hour = (int) $timeMatch[1];
            }

            return [
                'ip' => $matches[1],
                'datetime' => $matches[2],
                'method' => strtoupper($matches[3]),
                'path' => $matches[4],
                'status' => (int) $matches[5],
                'userAgent' => $matches[6],
                'hour' => $hour,
            ];
        }

        // Nginx default format without user agent
        $simplePattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"(\S+)\s+(\S+)\s+\S+"\s+(\d{3})\s+\S+/';
        if (preg_match($simplePattern, $line, $matches)) {
            $hour = null;
            if (preg_match('/\d{4}:(\d{2}):\d{2}:\d{2}/', $matches[2], $timeMatch)) {
                $hour = (int) $timeMatch[1];
            }

            return [
                'ip' => $matches[1],
                'datetime' => $matches[2],
                'method' => strtoupper($matches[3]),
                'path' => $matches[4],
                'status' => (int) $matches[5],
                'userAgent' => '',
                'hour' => $hour,
            ];
        }

        return null;
    }

    private function detectBot(string $userAgent): ?string
    {
        $ua = strtolower($userAgent);
        foreach (self::BOTS as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($ua, $pattern)) {
                    return $name;
                }
            }
        }
        return null;
    }

    private function generateRecommendations(array $stats): array
    {
        $recommendations = [];

        // 404 errors
        $total4xx = array_sum($stats['errors4xx']);
        if ($total4xx > 10) {
            $recommendations[] = [
                'type' => 'error',
                'title' => 'Erreurs 404 detectees',
                'titleEn' => '404 errors detected',
                'desc' => $total4xx . ' requetes en erreur 404. Corrigez ou redirigez ces URLs pour ameliorer le crawl budget.',
                'descEn' => $total4xx . ' requests returned 404. Fix or redirect these URLs to improve crawl budget.',
            ];
        }

        // 5xx errors
        $total5xx = array_sum($stats['errors5xx']);
        if ($total5xx > 0) {
            $recommendations[] = [
                'type' => 'critical',
                'title' => 'Erreurs serveur 5xx',
                'titleEn' => 'Server 5xx errors',
                'desc' => $total5xx . ' erreurs serveur detectees. Ces erreurs empechent l\'indexation et degradent l\'experience.',
                'descEn' => $total5xx . ' server errors detected. These prevent indexing and degrade user experience.',
            ];
        }

        // Crawl budget waste
        if ($stats['botRequests'] > 0) {
            $wasteRatio = $stats['resourcesCrawled'] / $stats['botRequests'];
            if ($wasteRatio > 0.3) {
                $pct = round($wasteRatio * 100);
                $recommendations[] = [
                    'type' => 'warning',
                    'title' => 'Gaspillage de crawl budget',
                    'titleEn' => 'Crawl budget waste',
                    'desc' => $pct . '% des requetes bots concernent des ressources (CSS, JS, images). Bloquez-les dans robots.txt.',
                    'descEn' => $pct . '% of bot requests target resources (CSS, JS, images). Block them in robots.txt.',
                ];
            }
        }

        // Googlebot presence
        if (!isset($stats['bots']['Googlebot']) || $stats['bots']['Googlebot'] < 5) {
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'Faible activite Googlebot',
                'titleEn' => 'Low Googlebot activity',
                'desc' => 'Googlebot a peu visite votre site. Verifiez votre sitemap et la Search Console.',
                'descEn' => 'Googlebot had low activity on your site. Check your sitemap and Search Console.',
            ];
        }

        // Unwanted bots
        $unwantedBots = ['Bytespider', 'PetalBot', 'MJ12bot', 'DotBot'];
        $unwantedCount = 0;
        foreach ($unwantedBots as $bot) {
            $unwantedCount += $stats['bots'][$bot] ?? 0;
        }
        if ($unwantedCount > 50) {
            $recommendations[] = [
                'type' => 'info',
                'title' => 'Bots indesirables detectes',
                'titleEn' => 'Unwanted bots detected',
                'desc' => $unwantedCount . ' requetes de bots indesirables. Bloquez-les dans robots.txt pour economiser des ressources.',
                'descEn' => $unwantedCount . ' requests from unwanted bots. Block them in robots.txt to save resources.',
            ];
        }

        // AI bots
        $aiBots = ['GPTBot', 'ClaudeBot', 'ChatGPT-User'];
        $aiCount = 0;
        foreach ($aiBots as $bot) {
            $aiCount += $stats['bots'][$bot] ?? 0;
        }
        if ($aiCount > 0) {
            $recommendations[] = [
                'type' => 'info',
                'title' => 'Bots IA detectes',
                'titleEn' => 'AI bots detected',
                'desc' => $aiCount . ' requetes de bots IA (GPTBot, ClaudeBot). Definissez votre politique dans robots.txt.',
                'descEn' => $aiCount . ' AI bot requests (GPTBot, ClaudeBot). Define your policy in robots.txt.',
            ];
        }

        return $recommendations;
    }
}
