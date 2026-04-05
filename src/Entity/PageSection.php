<?php

namespace SeoExpert\Engine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'page_sections')]
class PageSection
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['page:read', 'page:details'])]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\Column(length: 50)]
    #[Groups(['page:read', 'page:details'])]
    private string $type;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['page:read', 'page:details'])]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['page:read', 'page:details'])]
    private ?string $titleEn = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['page:read', 'page:details'])]
    private array $content = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['page:read', 'page:details'])]
    private ?array $contentEn = null;

    #[ORM\Column]
    #[Groups(['page:read', 'page:details'])]
    private int $position = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): static
    {
        $this->titleEn = $titleEn;
        return $this;
    }

    public function getContent(): array
    {
        return $this->content;
    }

    public function setContent(array $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getContentEn(): ?array
    {
        return $this->contentEn;
    }

    public function setContentEn(?array $contentEn): static
    {
        $this->contentEn = $contentEn;
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
