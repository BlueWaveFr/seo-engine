<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'internal_links')]
#[ORM\Index(columns: ['crawl_import_id'], name: 'idx_internal_links_import')]
#[ORM\Index(columns: ['source_url'], name: 'idx_internal_links_source')]
#[ORM\Index(columns: ['target_url'], name: 'idx_internal_links_target')]
class InternalLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CrawlImport::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CrawlImport $crawlImport;

    #[ORM\Column(length: 2000)]
    private string $sourceUrl;

    #[ORM\Column(length: 2000)]
    private string $targetUrl;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $anchorText = null;

    #[ORM\Column]
    private bool $isNofollow = false;

    public function getId(): ?int { return $this->id; }

    public function getCrawlImport(): CrawlImport { return $this->crawlImport; }
    public function setCrawlImport(CrawlImport $crawlImport): self { $this->crawlImport = $crawlImport; return $this; }

    public function getSourceUrl(): string { return $this->sourceUrl; }
    public function setSourceUrl(string $sourceUrl): self { $this->sourceUrl = mb_substr($sourceUrl, 0, 2000); return $this; }

    public function getTargetUrl(): string { return $this->targetUrl; }
    public function setTargetUrl(string $targetUrl): self { $this->targetUrl = mb_substr($targetUrl, 0, 2000); return $this; }

    public function getAnchorText(): ?string { return $this->anchorText; }
    public function setAnchorText(?string $anchorText): self { $this->anchorText = $anchorText !== null ? mb_substr($anchorText, 0, 500) : null; return $this; }

    public function isNofollow(): bool { return $this->isNofollow; }
    public function setIsNofollow(bool $isNofollow): self { $this->isNofollow = $isNofollow; return $this; }
}
