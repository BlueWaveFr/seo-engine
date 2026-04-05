<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'pages')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['page:read', 'page:details']]),
        new GetCollection(normalizationContext: ['groups' => ['page:read']]),
    ],
    normalizationContext: ['groups' => ['page:read']]
)]
class Page
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['page:read'])]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['page:read'])]
    private string $slug;

    #[ORM\Column(length: 255)]
    #[Groups(['page:read'])]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['page:read'])]
    private ?string $titleEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['page:read'])]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['page:read'])]
    private ?string $metaTitleEn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['page:read'])]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['page:read'])]
    private ?string $metaDescriptionEn = null;

    #[ORM\Column]
    #[Groups(['page:read'])]
    private bool $isPublished = false;

    #[ORM\Column]
    #[Groups(['page:read'])]
    private bool $showInMenu = false;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['page:read'])]
    private ?string $menuLabel = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['page:read'])]
    private ?string $menuLabelEn = null;

    #[ORM\Column]
    #[Groups(['page:read'])]
    private int $menuOrder = 0;

    #[ORM\OneToMany(mappedBy: 'page', targetEntity: PageSection::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['page:read', 'page:details'])]
    private Collection $sections;

    #[ORM\Column]
    #[Groups(['page:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['page:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->sections = new ArrayCollection();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
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

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }

    public function getMetaTitleEn(): ?string
    {
        return $this->metaTitleEn;
    }

    public function setMetaTitleEn(?string $metaTitleEn): static
    {
        $this->metaTitleEn = $metaTitleEn;
        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;
        return $this;
    }

    public function getMetaDescriptionEn(): ?string
    {
        return $this->metaDescriptionEn;
    }

    public function setMetaDescriptionEn(?string $metaDescriptionEn): static
    {
        $this->metaDescriptionEn = $metaDescriptionEn;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function getIsPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;
        return $this;
    }

    /**
     * @return Collection<int, PageSection>
     */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(PageSection $section): static
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
            $section->setPage($this);
        }
        return $this;
    }

    public function removeSection(PageSection $section): static
    {
        if ($this->sections->removeElement($section)) {
            if ($section->getPage() === $this) {
                $section->setPage(null);
            }
        }
        return $this;
    }

    public function clearSections(): static
    {
        $this->sections->clear();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isShowInMenu(): bool
    {
        return $this->showInMenu;
    }

    public function setShowInMenu(bool $showInMenu): static
    {
        $this->showInMenu = $showInMenu;
        return $this;
    }

    public function getMenuLabel(): ?string
    {
        return $this->menuLabel;
    }

    public function setMenuLabel(?string $menuLabel): static
    {
        $this->menuLabel = $menuLabel;
        return $this;
    }

    public function getMenuLabelEn(): ?string
    {
        return $this->menuLabelEn;
    }

    public function setMenuLabelEn(?string $menuLabelEn): static
    {
        $this->menuLabelEn = $menuLabelEn;
        return $this;
    }

    public function getMenuOrder(): int
    {
        return $this->menuOrder;
    }

    public function setMenuOrder(int $menuOrder): static
    {
        $this->menuOrder = $menuOrder;
        return $this;
    }
}
