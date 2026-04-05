<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'crawl_imports')]
class CrawlImport
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 20)]
    private string $status = 'pending'; // pending, processing, completed, failed

    #[ORM\Column(nullable: true)]
    private ?int $totalUrls = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalLinks = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summary = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->importedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getProject(): Project { return $this->project; }
    public function setProject(Project $project): self { $this->project = $project; return $this; }
    public function getFilename(): string { return $this->filename; }
    public function setFilename(string $filename): self { $this->filename = $filename; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getTotalUrls(): ?int { return $this->totalUrls; }
    public function setTotalUrls(?int $totalUrls): self { $this->totalUrls = $totalUrls; return $this; }
    public function getTotalLinks(): ?int { return $this->totalLinks; }
    public function setTotalLinks(?int $totalLinks): self { $this->totalLinks = $totalLinks; return $this; }
    public function getSummary(): ?array { return $this->summary; }
    public function setSummary(?array $summary): self { $this->summary = $summary; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): self { $this->errorMessage = $errorMessage; return $this; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): self { $this->completedAt = $completedAt; return $this; }
}
