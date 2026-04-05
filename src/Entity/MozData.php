<?php

namespace SeoExpert\Engine\Entity;

use App\Repository\MozDataRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MozDataRepository::class)]
#[ORM\Table(name: 'moz_data')]
#[ORM\Index(columns: ['domain'], name: 'idx_moz_data_domain')]
class MozData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $domain = null;

    #[ORM\Column(nullable: true)]
    private ?int $domainAuthority = null;

    #[ORM\Column(nullable: true)]
    private ?int $pageAuthority = null;

    #[ORM\Column(nullable: true)]
    private ?int $spamScore = null;

    #[ORM\Column(nullable: true)]
    private ?int $linkingRootDomains = null;

    #[ORM\Column(nullable: true)]
    private ?int $externalLinks = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topBacklinks = null;

    #[ORM\Column(nullable: true)]
    private ?int $backlinkCount = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawMetrics = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $fetchedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->fetchedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;
        return $this;
    }

    public function getDomainAuthority(): ?int
    {
        return $this->domainAuthority;
    }

    public function setDomainAuthority(?int $domainAuthority): static
    {
        $this->domainAuthority = $domainAuthority;
        return $this;
    }

    public function getPageAuthority(): ?int
    {
        return $this->pageAuthority;
    }

    public function setPageAuthority(?int $pageAuthority): static
    {
        $this->pageAuthority = $pageAuthority;
        return $this;
    }

    public function getSpamScore(): ?int
    {
        return $this->spamScore;
    }

    public function setSpamScore(?int $spamScore): static
    {
        $this->spamScore = $spamScore;
        return $this;
    }

    public function getLinkingRootDomains(): ?int
    {
        return $this->linkingRootDomains;
    }

    public function setLinkingRootDomains(?int $linkingRootDomains): static
    {
        $this->linkingRootDomains = $linkingRootDomains;
        return $this;
    }

    public function getExternalLinks(): ?int
    {
        return $this->externalLinks;
    }

    public function setExternalLinks(?int $externalLinks): static
    {
        $this->externalLinks = $externalLinks;
        return $this;
    }

    public function getTopBacklinks(): ?array
    {
        return $this->topBacklinks;
    }

    public function setTopBacklinks(?array $topBacklinks): static
    {
        $this->topBacklinks = $topBacklinks;
        return $this;
    }

    public function getBacklinkCount(): ?int
    {
        return $this->backlinkCount;
    }

    public function setBacklinkCount(?int $backlinkCount): static
    {
        $this->backlinkCount = $backlinkCount;
        return $this;
    }

    public function getRawMetrics(): ?array
    {
        return $this->rawMetrics;
    }

    public function setRawMetrics(?array $rawMetrics): static
    {
        $this->rawMetrics = $rawMetrics;
        return $this;
    }

    public function getFetchedAt(): ?\DateTimeInterface
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(\DateTimeInterface $fetchedAt): static
    {
        $this->fetchedAt = $fetchedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isStale(int $maxAgeHours = 24): bool
    {
        $now = new \DateTime();
        $diff = $now->diff($this->fetchedAt);
        $hours = ($diff->days * 24) + $diff->h;
        return $hours >= $maxAgeHours;
    }
}
