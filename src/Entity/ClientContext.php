<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'client_contexts')]
#[ORM\UniqueConstraint(name: 'unique_client_context_project', columns: ['project_id'])]
#[ORM\HasLifecycleCallbacks]
class ClientContext
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $industry = null;

    #[ORM\Column(type: 'json')]
    private array $competitors = [];

    #[ORM\Column(type: 'json')]
    private array $targetKeywords = [];

    #[ORM\Column(nullable: true)]
    private ?int $trafficGoal = null;

    #[ORM\Column(type: 'json')]
    private array $recentActions = [];

    #[ORM\Column(type: 'json')]
    private array $decisionsHistory = [];

    #[ORM\Column(type: 'json')]
    private array $baselineMetrics = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $editorialRules = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): static
    {
        $this->domain = $domain;
        return $this;
    }

    public function getIndustry(): ?string
    {
        return $this->industry;
    }

    public function setIndustry(?string $industry): static
    {
        $this->industry = $industry;
        return $this;
    }

    public function getCompetitors(): array
    {
        return $this->competitors;
    }

    public function setCompetitors(array $competitors): static
    {
        $this->competitors = $competitors;
        return $this;
    }

    public function getTargetKeywords(): array
    {
        return $this->targetKeywords;
    }

    public function setTargetKeywords(array $targetKeywords): static
    {
        $this->targetKeywords = $targetKeywords;
        return $this;
    }

    public function getTrafficGoal(): ?int
    {
        return $this->trafficGoal;
    }

    public function setTrafficGoal(?int $trafficGoal): static
    {
        $this->trafficGoal = $trafficGoal;
        return $this;
    }

    public function getRecentActions(): array
    {
        return $this->recentActions;
    }

    public function setRecentActions(array $recentActions): static
    {
        $this->recentActions = $recentActions;
        return $this;
    }

    /**
     * Add an action to the sliding window, keeping only the last 20 entries.
     */
    public function addRecentAction(array $action): static
    {
        $this->recentActions[] = $action;
        if (count($this->recentActions) > 20) {
            $this->recentActions = array_slice($this->recentActions, -20);
        }
        return $this;
    }

    public function getDecisionsHistory(): array
    {
        return $this->decisionsHistory;
    }

    public function setDecisionsHistory(array $decisionsHistory): static
    {
        $this->decisionsHistory = $decisionsHistory;
        return $this;
    }

    public function getBaselineMetrics(): array
    {
        return $this->baselineMetrics;
    }

    public function setBaselineMetrics(array $baselineMetrics): static
    {
        $this->baselineMetrics = $baselineMetrics;
        return $this;
    }

    public function getEditorialRules(): ?string
    {
        return $this->editorialRules;
    }

    public function setEditorialRules(?string $editorialRules): static
    {
        $this->editorialRules = $editorialRules;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
