<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'connection_logs')]
#[ORM\Index(columns: ['login_at'], name: 'idx_connection_login_at')]
class ConnectionLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column]
    private bool $success = true;

    #[ORM\Column(length: 50)]
    private string $method = 'email'; // email, google, linkedin, x

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $loginAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->loginAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function isSuccess(): bool { return $this->success; }
    public function setSuccess(bool $success): static { $this->success = $success; return $this; }
    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): static { $this->method = $method; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): static { $this->userAgent = $userAgent; return $this; }
    public function getLoginAt(): \DateTimeImmutable { return $this->loginAt; }
    public function setLoginAt(\DateTimeImmutable $loginAt): static { $this->loginAt = $loginAt; return $this; }
}
