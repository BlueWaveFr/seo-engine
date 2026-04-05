<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'plan_features')]
class PlanFeature
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['plan:read', 'plan:details'])]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'features')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Plan $plan = null;

    #[ORM\Column(length: 255)]
    #[Groups(['plan:read', 'plan:details'])]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['plan:read', 'plan:details'])]
    private ?string $nameEn = null;

    #[ORM\Column]
    #[Groups(['plan:read', 'plan:details'])]
    private bool $included = true;

    #[ORM\Column]
    #[Groups(['plan:read', 'plan:details'])]
    private int $position = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function setNameEn(?string $nameEn): static
    {
        $this->nameEn = $nameEn;
        return $this;
    }

    public function isIncluded(): bool
    {
        return $this->included;
    }

    public function setIncluded(bool $included): static
    {
        $this->included = $included;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }
}
