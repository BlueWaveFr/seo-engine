<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'agent_schedules')]
#[ORM\Index(columns: ['project_id'], name: 'idx_agent_schedule_project')]
#[ORM\Index(columns: ['is_active'], name: 'idx_agent_schedule_active')]
#[ORM\Index(columns: ['next_run_at'], name: 'idx_agent_schedule_next_run')]
#[ORM\HasLifecycleCallbacks]
class AgentSchedule
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 50)]
    private string $taskType;

    #[ORM\Column(length: 100)]
    private string $cronExpression;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\OneToMany(mappedBy: 'schedule', targetEntity: AgentTask::class)]
    private Collection $tasks;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->tasks = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTaskType(): string
    {
        return $this->taskType;
    }

    public function setTaskType(string $taskType): static
    {
        $this->taskType = $taskType;
        return $this;
    }

    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;
        return $this;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): static
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return Collection<int, AgentTask>
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
