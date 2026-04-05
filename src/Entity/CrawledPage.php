<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'crawled_pages')]
#[ORM\Index(columns: ['crawl_import_id'], name: 'idx_crawled_pages_import')]
#[ORM\Index(columns: ['project_id'], name: 'idx_crawled_pages_project')]
#[ORM\Index(columns: ['status_code'], name: 'idx_crawled_pages_status')]
class CrawledPage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CrawlImport::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CrawlImport $crawlImport;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 2000)]
    private string $url;

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $crawlDepth = null;

    #[ORM\Column(nullable: true)]
    private ?int $wordCount = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $h1 = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $h2 = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(nullable: true)]
    private ?int $responseTimeMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $internalOutlinks = null;

    #[ORM\Column(nullable: true)]
    private ?int $uniqueInternalOutlinks = null;

    #[ORM\Column(nullable: true)]
    private ?int $externalOutlinks = null;

    #[ORM\Column(nullable: true)]
    private ?float $textHtmlRatio = null;

    #[ORM\Column(nullable: true)]
    private ?float $readabilityScore = null;

    #[ORM\Column]
    private bool $isRedirect = false;

    #[ORM\Column]
    private bool $isCanonicalised = false;

    #[ORM\Column(nullable: true)]
    private ?int $pageSize = null;

    // Enriched from inlink_counts
    #[ORM\Column(nullable: true)]
    private ?int $inlinksCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $uniqueInlinksCount = null;

    // Enriched from GSC data
    #[ORM\Column(nullable: true)]
    private ?int $gscClicks = null;

    #[ORM\Column(nullable: true)]
    private ?int $gscImpressions = null;

    #[ORM\Column(nullable: true)]
    private ?float $gscCtr = null;

    #[ORM\Column(nullable: true)]
    private ?float $gscPosition = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid { return $this->id; }

    public function getCrawlImport(): CrawlImport { return $this->crawlImport; }
    public function setCrawlImport(CrawlImport $crawlImport): self { $this->crawlImport = $crawlImport; return $this; }

    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }

    public function getStatusCode(): ?int { return $this->statusCode; }
    public function setStatusCode(?int $statusCode): self { $this->statusCode = $statusCode; return $this; }

    public function getCrawlDepth(): ?int { return $this->crawlDepth; }
    public function setCrawlDepth(?int $crawlDepth): self { $this->crawlDepth = $crawlDepth; return $this; }

    public function getWordCount(): ?int { return $this->wordCount; }
    public function setWordCount(?int $wordCount): self { $this->wordCount = $wordCount; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title !== null ? mb_substr($title, 0, 1000) : null; return $this; }

    public function getH1(): ?string { return $this->h1; }
    public function setH1(?string $h1): self { $this->h1 = $h1 !== null ? mb_substr($h1, 0, 1000) : null; return $this; }

    public function getH2(): ?string { return $this->h2; }
    public function setH2(?string $h2): self { $this->h2 = $h2 !== null ? mb_substr($h2, 0, 1000) : null; return $this; }

    public function getMetaDescription(): ?string { return $this->metaDescription; }
    public function setMetaDescription(?string $metaDescription): self { $this->metaDescription = $metaDescription; return $this; }

    public function getResponseTimeMs(): ?int { return $this->responseTimeMs; }
    public function setResponseTimeMs(?int $responseTimeMs): self { $this->responseTimeMs = $responseTimeMs; return $this; }

    public function getInternalOutlinks(): ?int { return $this->internalOutlinks; }
    public function setInternalOutlinks(?int $internalOutlinks): self { $this->internalOutlinks = $internalOutlinks; return $this; }

    public function getUniqueInternalOutlinks(): ?int { return $this->uniqueInternalOutlinks; }
    public function setUniqueInternalOutlinks(?int $v): self { $this->uniqueInternalOutlinks = $v; return $this; }

    public function getExternalOutlinks(): ?int { return $this->externalOutlinks; }
    public function setExternalOutlinks(?int $externalOutlinks): self { $this->externalOutlinks = $externalOutlinks; return $this; }

    public function getTextHtmlRatio(): ?float { return $this->textHtmlRatio; }
    public function setTextHtmlRatio(?float $textHtmlRatio): self { $this->textHtmlRatio = $textHtmlRatio; return $this; }

    public function getReadabilityScore(): ?float { return $this->readabilityScore; }
    public function setReadabilityScore(?float $readabilityScore): self { $this->readabilityScore = $readabilityScore; return $this; }

    public function isRedirect(): bool { return $this->isRedirect; }
    public function setIsRedirect(bool $isRedirect): self { $this->isRedirect = $isRedirect; return $this; }

    public function isCanonicalised(): bool { return $this->isCanonicalised; }
    public function setIsCanonicalised(bool $isCanonicalised): self { $this->isCanonicalised = $isCanonicalised; return $this; }

    public function getPageSize(): ?int { return $this->pageSize; }
    public function setPageSize(?int $pageSize): self { $this->pageSize = $pageSize; return $this; }

    public function getInlinksCount(): ?int { return $this->inlinksCount; }
    public function setInlinksCount(?int $inlinksCount): self { $this->inlinksCount = $inlinksCount; return $this; }

    public function getUniqueInlinksCount(): ?int { return $this->uniqueInlinksCount; }
    public function setUniqueInlinksCount(?int $v): self { $this->uniqueInlinksCount = $v; return $this; }

    public function getGscClicks(): ?int { return $this->gscClicks; }
    public function setGscClicks(?int $gscClicks): self { $this->gscClicks = $gscClicks; return $this; }

    public function getGscImpressions(): ?int { return $this->gscImpressions; }
    public function setGscImpressions(?int $gscImpressions): self { $this->gscImpressions = $gscImpressions; return $this; }

    public function getGscCtr(): ?float { return $this->gscCtr; }
    public function setGscCtr(?float $gscCtr): self { $this->gscCtr = $gscCtr; return $this; }

    public function getGscPosition(): ?float { return $this->gscPosition; }
    public function setGscPosition(?float $gscPosition): self { $this->gscPosition = $gscPosition; return $this; }
}
