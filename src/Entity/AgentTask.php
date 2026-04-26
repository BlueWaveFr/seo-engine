<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'agent_tasks')]
#[ORM\Index(columns: ['project_id'], name: 'idx_agent_task_project')]
#[ORM\Index(columns: ['status'], name: 'idx_agent_task_status')]
#[ORM\Index(columns: ['task_type'], name: 'idx_agent_task_type')]
#[ORM\Index(columns: ['created_at'], name: 'idx_agent_task_created_at')]
#[ORM\Index(columns: ['project_id', 'status'], name: 'idx_agent_task_project_status')]
class AgentTask
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AgentSchedule::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AgentSchedule $schedule = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 50)]
    private string $taskType;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $plan = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $llmReasoning = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $llmModel = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tokenUsage = null;

    #[ORM\OneToMany(mappedBy: 'task', targetEntity: AgentLog::class, cascade: ['persist', 'remove'])]
    private Collection $logs;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->logs = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSchedule(): ?AgentSchedule
    {
        return $this->schedule;
    }

    public function setSchedule(?AgentSchedule $schedule): static
    {
        $this->schedule = $schedule;
        return $this;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getPlan(): ?array
    {
        return $this->plan;
    }

    public function setPlan(?array $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function setResult(?array $result): static
    {
        $this->result = $result;
        return $this;
    }

    public function getLlmReasoning(): ?string
    {
        return $this->llmReasoning;
    }

    public function setLlmReasoning(?string $llmReasoning): static
    {
        $this->llmReasoning = $llmReasoning;
        return $this;
    }

    public function getLlmModel(): ?string
    {
        return $this->llmModel;
    }

    public function setLlmModel(?string $llmModel): static
    {
        $this->llmModel = $llmModel;
        return $this;
    }

    public function getTokenUsage(): ?array
    {
        return $this->tokenUsage;
    }

    public function setTokenUsage(?array $tokenUsage): static
    {
        $this->tokenUsage = $tokenUsage;
        return $this;
    }

    /**
     * @return Collection<int, AgentLog>
     */
    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function addLog(AgentLog $log): static
    {
        if (!$this->logs->contains($log)) {
            $this->logs->add($log);
            $log->setTask($this);
        }
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function markAsAwaitingApproval(): static
    {
        $this->status = self::STATUS_AWAITING_APPROVAL;
        return $this;
    }

    public function markAsApproved(): static
    {
        $this->status = self::STATUS_APPROVED;
        $this->approvedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsRejected(): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->completedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsCompleted(?array $result = null): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
        if ($result !== null) {
            $this->result = $result;
        }
        return $this;
    }

    public function markAsFailed(?array $result = null): static
    {
        $this->status = self::STATUS_FAILED;
        $this->completedAt = new \DateTimeImmutable();
        if ($result !== null) {
            $this->result = $result;
        }
        return $this;
    }
}
