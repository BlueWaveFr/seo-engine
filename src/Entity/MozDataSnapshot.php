<?php

namespace SeoExpert\Engine\Entity;

use App\Repository\MozDataSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MozDataSnapshotRepository::class)]
#[ORM\Table(name: 'moz_data_snapshots')]
#[ORM\Index(columns: ['domain'], name: 'idx_moz_snapshot_domain')]
#[ORM\Index(columns: ['snapshot_date'], name: 'idx_moz_snapshot_date')]
class MozDataSnapshot
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

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $snapshotDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->snapshotDate = new \DateTime();
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

    public function getSnapshotDate(): ?\DateTimeInterface
    {
        return $this->snapshotDate;
    }

    public function setSnapshotDate(\DateTimeInterface $snapshotDate): static
    {
        $this->snapshotDate = $snapshotDate;
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
}
