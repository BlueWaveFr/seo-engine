<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\CrawlImport;
use SeoExpert\Engine\Entity\CrawledPage;
use SeoExpert\Engine\Entity\InternalLink;
use SeoExpert\Engine\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ScreamingFrogImportService
{
    private const DERBY_DIR = '/opt/derby';
    private const BATCH_SIZE_PAGES = 100;
    private const BATCH_SIZE_LINKS = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Import a .dbseospider file into the project.
     */
    public function import(Project $project, string $filePath): CrawlImport
    {
        // Allow long execution for large crawls
        set_time_limit(600);
        ini_set('memory_limit', '1G');
        $import = new CrawlImport();
        $import->setProject($project);
        $import->setFilename(basename($filePath));
        $import->setStatus('processing');

        $this->em->persist($import);
        $this->em->flush();

        $tempDir = sys_get_temp_dir() . '/sf_import_' . $import->getId()->toString();

        try {
            // 1. Extract the ZIP
            $this->extractArchive($filePath, $tempDir);

            // 2. Find the Derby DB path
            $dbPath = $this->findDerbyDb($tempDir);

            // 3. Run Java extraction
            $outputDir = $tempDir . '/export';
            mkdir($outputDir, 0777, true);
            $this->runDerbyExport($dbPath, $outputDir);

            // 4. Parse and import data
            $urlCount = $this->importUrls($import, $project, $outputDir);
            $linkCount = $this->importLinks($import, $outputDir);
            $this->enrichWithInlinkCounts($import, $outputDir);
            $this->enrichWithGSC($import, $outputDir);

            // 5. Compute summary
            $summary = $this->computeSummary($import);

            $import->setTotalUrls($urlCount);
            $import->setTotalLinks($linkCount);
            $import->setSummary($summary);
            $import->setStatus('completed');
            $import->setCompletedAt(new \DateTimeImmutable());

            $this->em->flush();
            $this->logger->info('Screaming Frog import completed', [
                'project' => $project->getName(),
                'urls' => $urlCount,
                'links' => $linkCount,
            ]);
        } catch (\Exception $e) {
            $import->setStatus('failed');
            $import->setErrorMessage($e->getMessage());
            $this->em->flush();

            $this->logger->error('Screaming Frog import failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            // Cleanup temp files
            $this->recursiveDelete($tempDir);
        }

        return $import;
    }

    private function extractArchive(string $filePath, string $tempDir): void
    {
        mkdir($tempDir, 0777, true);

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Cannot open .dbseospider file');
        }
        $zip->extractTo($tempDir);
        $zip->close();
    }

    private function findDerbyDb(string $tempDir): string
    {
        // Find the results_*/sql directory
        $dirs = glob($tempDir . '/results_*/sql');
        if (empty($dirs)) {
            throw new \RuntimeException('Derby database not found in archive');
        }

        $dbPath = $dirs[0];

        // Remove lock files
        @unlink($dbPath . '/db.lck');
        @unlink($dbPath . '/dbex.lck');

        return $dbPath;
    }

    private function runDerbyExport(string $dbPath, string $outputDir): void
    {
        $javaCmd = sprintf(
            'java -cp "%s/derby.jar:%s/derbyshared.jar:%s" DerbyExport "%s" "%s" 2>&1',
            self::DERBY_DIR,
            self::DERBY_DIR,
            self::DERBY_DIR,
            $dbPath,
            $outputDir
        );

        $output = [];
        $returnCode = 0;
        exec($javaCmd, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->logger->info('Derby export output: ' . $outputStr);

        if ($returnCode !== 0 || !str_contains($outputStr, 'EXPORT_COMPLETE')) {
            throw new \RuntimeException('Derby export failed: ' . $outputStr);
        }
    }

    private function importUrls(CrawlImport $import, Project $project, string $outputDir): int
    {
        $file = $outputDir . '/urls.json';
        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid urls.json');
        }

        $count = 0;
        foreach ($data as $row) {
            $page = new CrawledPage();
            $page->setCrawlImport($import);
            $page->setProject($project);
            $page->setUrl($row['url'] ?? '');
            $page->setStatusCode($row['status_code'] ?? null);
            $page->setCrawlDepth($row['crawl_depth'] ?? null);
            $page->setWordCount($row['word_count'] ?? null);
            $page->setTitle($row['title'] ?? null);
            $page->setH1($row['h1'] ?? null);
            $page->setH2($row['h2'] ?? null);
            $page->setMetaDescription($row['meta_description'] ?? null);
            $page->setResponseTimeMs(isset($row['response_time_ms']) ? (int) $row['response_time_ms'] : null);
            $page->setInternalOutlinks($row['internal_outlinks'] ?? null);
            $page->setUniqueInternalOutlinks($row['unique_internal_outlinks'] ?? null);
            $page->setExternalOutlinks($row['external_outlinks'] ?? null);
            $page->setTextHtmlRatio(isset($row['text_html_ratio']) ? (float) $row['text_html_ratio'] : null);
            $page->setReadabilityScore(isset($row['readability']) ? (float) $row['readability'] : null);
            $page->setIsRedirect($row['is_redirect'] ?? false);
            $page->setIsCanonicalised($row['is_canonicalised'] ?? false);
            $page->setPageSize($row['page_size'] ?? null);

            $this->em->persist($page);
            $count++;

            if ($count % self::BATCH_SIZE_PAGES === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();
        return $count;
    }

    private function importLinks(CrawlImport $import, string $outputDir): int
    {
        $file = $outputDir . '/links.json';
        if (!file_exists($file)) {
            return 0;
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true);
        unset($content);

        if (!is_array($data)) {
            return 0;
        }

        // Get the site domain to filter internal links only
        $pages = $this->em->getRepository(CrawledPage::class)->findBy(['crawlImport' => $import], null, 1);
        $siteDomain = '';
        if (!empty($pages)) {
            $parsed = parse_url($pages[0]->getUrl());
            $siteDomain = $parsed['host'] ?? '';
        }

        // Use native SQL for bulk insert (much faster than ORM for 300K+ rows)
        $conn = $this->em->getConnection();
        $importId = $import->getId()->toString();
        $count = 0;
        $batch = [];

        foreach ($data as $row) {
            $src = $row['src'] ?? '';
            $dst = $row['dst'] ?? '';

            if ($siteDomain && !str_contains($dst, $siteDomain)) {
                continue;
            }

            $batch[] = [
                mb_substr($src, 0, 2000),
                mb_substr($dst, 0, 2000),
                mb_substr($row['anchor'] ?? '', 0, 500),
                ($row['nofollow'] ?? false) ? 'true' : 'false',
                $importId,
            ];
            $count++;

            if (count($batch) >= 200) {
                $this->bulkInsertLinks($conn, $batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->bulkInsertLinks($conn, $batch);
        }

        return $count;
    }

    private function bulkInsertLinks(\Doctrine\DBAL\Connection $conn, array $batch): void
    {
        $values = [];
        $params = [];
        $i = 0;
        foreach ($batch as $row) {
            $values[] = "(:s{$i}, :t{$i}, :a{$i}, :n{$i}::boolean, :c{$i}::uuid)";
            $params["s{$i}"] = $row[0];
            $params["t{$i}"] = $row[1];
            $params["a{$i}"] = $row[2];
            $params["n{$i}"] = $row[3];
            $params["c{$i}"] = $row[4];
            $i++;
        }

        $sql = 'INSERT INTO internal_links (source_url, target_url, anchor_text, is_nofollow, crawl_import_id) VALUES ' . implode(',', $values);
        $conn->executeStatement($sql, $params);
    }

    private function enrichWithInlinkCounts(CrawlImport $import, string $outputDir): void
    {
        $file = $outputDir . '/inlink_counts.json';
        if (!file_exists($file)) {
            return;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return;
        }

        $conn = $this->em->getConnection();
        $importId = $import->getId()->toString();

        foreach ($data as $row) {
            $url = $row['url'] ?? '';
            if (!$url) continue;

            $conn->executeStatement(
                'UPDATE crawled_pages SET inlinks_count = ?, unique_inlinks_count = ? WHERE crawl_import_id = ?::uuid AND url = ?',
                [$row['inlinks'] ?? 0, $row['unique_inlinks'] ?? 0, $importId, $url]
            );
        }
    }

    private function enrichWithGSC(CrawlImport $import, string $outputDir): void
    {
        $file = $outputDir . '/gsc.json';
        if (!file_exists($file)) {
            return;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || empty($data)) {
            return;
        }

        $conn = $this->em->getConnection();
        $importId = $import->getId()->toString();

        foreach ($data as $row) {
            $url = $row['url'] ?? '';
            if (!$url) continue;

            $conn->executeStatement(
                'UPDATE crawled_pages SET gsc_clicks = ?, gsc_impressions = ?, gsc_ctr = ?, gsc_position = ? WHERE crawl_import_id = ?::uuid AND url = ?',
                [
                    isset($row['clicks']) ? (int) $row['clicks'] : null,
                    isset($row['impressions']) ? (int) $row['impressions'] : null,
                    isset($row['ctr']) ? (float) $row['ctr'] : null,
                    isset($row['position']) ? (float) $row['position'] : null,
                    $importId,
                    $url,
                ]
            );
        }
    }

    private function computeSummary(CrawlImport $import): array
    {
        $conn = $this->em->getConnection();
        $importId = $import->getId()->toString();

        // Status code distribution
        $statusDist = $conn->fetchAllAssociative(
            'SELECT status_code, COUNT(*) as cnt FROM crawled_pages WHERE crawl_import_id = ? GROUP BY status_code ORDER BY status_code',
            [$importId]
        );

        $status2xx = 0;
        $status3xx = 0;
        $status4xx = 0;
        $status5xx = 0;
        foreach ($statusDist as $row) {
            $code = (int) $row['status_code'];
            $cnt = (int) $row['cnt'];
            if ($code >= 200 && $code < 300) $status2xx += $cnt;
            elseif ($code >= 300 && $code < 400) $status3xx += $cnt;
            elseif ($code >= 400 && $code < 500) $status4xx += $cnt;
            elseif ($code >= 500) $status5xx += $cnt;
        }

        // Orphan pages (0 inlinks among internal HTML pages)
        $orphanCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND (inlinks_count IS NULL OR inlinks_count = 0) AND status_code = 200',
            [$importId]
        );

        // Missing titles
        $missingTitles = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND (title IS NULL OR title = \'\') AND status_code = 200',
            [$importId]
        );

        // Missing H1
        $missingH1 = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND (h1 IS NULL OR h1 = \'\') AND status_code = 200',
            [$importId]
        );

        // Missing meta descriptions
        $missingMeta = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND (meta_description IS NULL OR meta_description = \'\') AND status_code = 200',
            [$importId]
        );

        // Deep pages (depth > 3)
        $deepPages = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND crawl_depth > 3 AND status_code = 200',
            [$importId]
        );

        // Slow pages (> 1000ms)
        $slowPages = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND response_time_ms > 1000 AND status_code = 200',
            [$importId]
        );

        // Average metrics
        $avgMetrics = $conn->fetchAssociative(
            'SELECT AVG(crawl_depth) as avg_depth, AVG(response_time_ms) as avg_response, AVG(word_count) as avg_words FROM crawled_pages WHERE crawl_import_id = ? AND status_code = 200',
            [$importId]
        );

        // GSC data count
        $gscCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM crawled_pages WHERE crawl_import_id = ? AND gsc_clicks IS NOT NULL',
            [$importId]
        );

        return [
            'status_2xx' => $status2xx,
            'status_3xx' => $status3xx,
            'status_4xx' => $status4xx,
            'status_5xx' => $status5xx,
            'orphan_pages' => $orphanCount,
            'missing_titles' => $missingTitles,
            'missing_h1' => $missingH1,
            'missing_meta_descriptions' => $missingMeta,
            'deep_pages' => $deepPages,
            'slow_pages' => $slowPages,
            'avg_depth' => round((float) ($avgMetrics['avg_depth'] ?? 0), 1),
            'avg_response_ms' => round((float) ($avgMetrics['avg_response'] ?? 0)),
            'avg_word_count' => round((float) ($avgMetrics['avg_words'] ?? 0)),
            'gsc_pages' => $gscCount,
        ];
    }

    /**
     * Get analysis data for the dashboard.
     */
    public function getAnalysis(CrawlImport $import): array
    {
        $conn = $this->em->getConnection();
        $importId = $import->getId()->toString();

        // Top pages by inlinks
        $topByInlinks = $conn->fetchAllAssociative(
            'SELECT url, title, inlinks_count, unique_inlinks_count, word_count, crawl_depth FROM crawled_pages WHERE crawl_import_id = ? AND status_code = 200 AND inlinks_count > 0 ORDER BY inlinks_count DESC LIMIT 20',
            [$importId]
        );

        // Orphan pages
        $orphanPages = $conn->fetchAllAssociative(
            'SELECT url, title, word_count, crawl_depth FROM crawled_pages WHERE crawl_import_id = ? AND (inlinks_count IS NULL OR inlinks_count = 0) AND status_code = 200 ORDER BY word_count DESC LIMIT 30',
            [$importId]
        );

        // Pages with errors (4xx, 5xx)
        $errorPages = $conn->fetchAllAssociative(
            'SELECT url, status_code, title FROM crawled_pages WHERE crawl_import_id = ? AND status_code >= 400 ORDER BY status_code, url LIMIT 50',
            [$importId]
        );

        // Slow pages
        $slowPages = $conn->fetchAllAssociative(
            'SELECT url, title, response_time_ms, word_count FROM crawled_pages WHERE crawl_import_id = ? AND response_time_ms > 1000 AND status_code = 200 ORDER BY response_time_ms DESC LIMIT 20',
            [$importId]
        );

        // Deep pages
        $deepPages = $conn->fetchAllAssociative(
            'SELECT url, title, crawl_depth, inlinks_count FROM crawled_pages WHERE crawl_import_id = ? AND crawl_depth > 3 AND status_code = 200 ORDER BY crawl_depth DESC LIMIT 20',
            [$importId]
        );

        // Missing on-page elements
        $missingTitles = $conn->fetchAllAssociative(
            'SELECT url, h1, word_count FROM crawled_pages WHERE crawl_import_id = ? AND (title IS NULL OR title = \'\') AND status_code = 200 LIMIT 20',
            [$importId]
        );

        $missingH1 = $conn->fetchAllAssociative(
            'SELECT url, title, word_count FROM crawled_pages WHERE crawl_import_id = ? AND (h1 IS NULL OR h1 = \'\') AND status_code = 200 LIMIT 20',
            [$importId]
        );

        // Top GSC pages
        $topGsc = $conn->fetchAllAssociative(
            'SELECT url, title, gsc_clicks, gsc_impressions, gsc_ctr, gsc_position FROM crawled_pages WHERE crawl_import_id = ? AND gsc_clicks IS NOT NULL ORDER BY gsc_clicks DESC LIMIT 20',
            [$importId]
        );

        // Depth distribution
        $depthDist = $conn->fetchAllAssociative(
            'SELECT crawl_depth, COUNT(*) as cnt FROM crawled_pages WHERE crawl_import_id = ? AND status_code = 200 GROUP BY crawl_depth ORDER BY crawl_depth',
            [$importId]
        );

        return [
            'summary' => $import->getSummary(),
            'top_by_inlinks' => $topByInlinks,
            'orphan_pages' => $orphanPages,
            'error_pages' => $errorPages,
            'slow_pages' => $slowPages,
            'deep_pages' => $deepPages,
            'missing_titles' => $missingTitles,
            'missing_h1' => $missingH1,
            'top_gsc' => $topGsc,
            'depth_distribution' => $depthDist,
        ];
    }

    /**
     * Get paginated pages list with filters.
     */
    public function getPages(CrawlImport $import, array $filters = [], int $page = 1, int $limit = 50): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p')
            ->from(CrawledPage::class, 'p')
            ->where('p.crawlImport = :import')
            ->setParameter('import', $import);

        if (!empty($filters['status_code'])) {
            $qb->andWhere('p.statusCode = :status')->setParameter('status', $filters['status_code']);
        }
        if (!empty($filters['min_depth'])) {
            $qb->andWhere('p.crawlDepth >= :minDepth')->setParameter('minDepth', $filters['min_depth']);
        }
        if (!empty($filters['search'])) {
            $qb->andWhere('p.url LIKE :search OR p.title LIKE :search')->setParameter('search', '%' . $filters['search'] . '%');
        }
        if (isset($filters['orphans']) && $filters['orphans']) {
            $qb->andWhere('(p.inlinksCount IS NULL OR p.inlinksCount = 0)');
        }

        $total = (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $pages = $qb->orderBy('p.url', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'pages' => array_map(fn(CrawledPage $p) => $this->serializePage($p), $pages),
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    private function serializePage(CrawledPage $page): array
    {
        return [
            'id' => $page->getId()->toString(),
            'url' => $page->getUrl(),
            'status_code' => $page->getStatusCode(),
            'crawl_depth' => $page->getCrawlDepth(),
            'word_count' => $page->getWordCount(),
            'title' => $page->getTitle(),
            'h1' => $page->getH1(),
            'meta_description' => $page->getMetaDescription(),
            'response_time_ms' => $page->getResponseTimeMs(),
            'internal_outlinks' => $page->getInternalOutlinks(),
            'unique_internal_outlinks' => $page->getUniqueInternalOutlinks(),
            'external_outlinks' => $page->getExternalOutlinks(),
            'inlinks_count' => $page->getInlinksCount(),
            'unique_inlinks_count' => $page->getUniqueInlinksCount(),
            'is_redirect' => $page->isRedirect(),
            'page_size' => $page->getPageSize(),
            'gsc_clicks' => $page->getGscClicks(),
            'gsc_impressions' => $page->getGscImpressions(),
            'gsc_position' => $page->getGscPosition(),
        ];
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
