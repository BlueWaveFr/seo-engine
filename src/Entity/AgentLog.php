<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'agent_logs')]
#[ORM\Index(columns: ['task_id'], name: 'idx_agent_log_task')]
#[ORM\Index(columns: ['project_id'], name: 'idx_agent_log_project')]
#[ORM\Index(columns: ['created_at'], name: 'idx_agent_log_created_at')]
#[ORM\Index(columns: ['action'], name: 'idx_agent_log_action')]
#[ORM\Index(columns: ['status'], name: 'idx_agent_log_status')]
class AgentLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AgentTask::class, inversedBy: 'logs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentTask $task;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $params = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(nullable: true)]
    private ?int $duration = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTask(): AgentTask
    {
        return $this->task;
    }

    public function setTask(AgentTask $task): static
    {
        $this->task = $task;
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

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getParams(): ?array
    {
        return $this->params;
    }

    public function setParams(?array $params): static
    {
        $this->params = $params;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
