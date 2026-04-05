<?php

namespace SeoExpert\Engine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'guide_chapters')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['guide_id', 'position'], name: 'idx_chapter_position')]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['guide_chapter:read', 'guide_chapter:details']]),
        new GetCollection(
            uriTemplate: '/guides/{guideId}/chapters',
            uriVariables: [
                'guideId' => new Link(fromClass: Guide::class, toProperty: 'guide'),
            ],
            normalizationContext: ['groups' => ['guide_chapter:read']]
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['guide_chapter:write']]
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
            denormalizationContext: ['groups' => ['guide_chapter:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['guide_chapter:read']],
    order: ['position' => 'ASC']
)]
class GuideChapter
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['guide_chapter:read', 'guide:read', 'guide:details'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Guide::class, inversedBy: 'chapters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['guide_chapter:write'])]
    private ?Guide $guide = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:read', 'guide:details'])]
    private string $title;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:read', 'guide:details'])]
    private string $slug;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:read'])]
    private ?string $summary = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['guide_chapter:read', 'guide_chapter:details', 'guide_chapter:write', 'guide:details'])]
    private string $content;

    #[ORM\Column]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:read', 'guide:details'])]
    private int $position = 0;

    #[ORM\Column]
    #[Groups(['guide_chapter:read', 'guide:read', 'guide:details'])]
    private int $readingTime = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:details'])]
    private ?array $keyTakeaways = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:details'])]
    private ?array $practicalExercises = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['guide_chapter:read', 'guide_chapter:write', 'guide:read'])]
    private ?string $featuredImage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guide_chapter:read', 'guide_chapter:write'])]
    private ?string $featuredImageAlt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['guide_chapter:read', 'guide_chapter:details'])]
    private ?array $tableOfContents = null;

    #[ORM\Column]
    #[Groups(['guide_chapter:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['guide_chapter:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onPrePersist(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->calculateReadingTime();
        $this->generateTableOfContents();
    }

    private function calculateReadingTime(): void
    {
        $wordCount = str_word_count(strip_tags($this->content));
        $this->readingTime = max(1, (int) ceil($wordCount / 200));
    }

    private function generateTableOfContents(): void
    {
        preg_match_all('/<h([2-3])[^>]*>(.*?)<\/h\1>/i', $this->content, $matches, PREG_SET_ORDER);

        $toc = [];
        foreach ($matches as $match) {
            $level = (int) $match[1];
            $text = strip_tags($match[2]);
            $id = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));

            $toc[] = [
                'level' => $level,
                'text' => $text,
                'id' => $id,
            ];
        }

        $this->tableOfContents = $toc;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGuide(): ?Guide
    {
        return $this->guide;
    }

    public function setGuide(?Guide $guide): static
    {
        $this->guide = $guide;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
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

    public function getReadingTime(): int
    {
        return $this->readingTime;
    }

    public function getKeyTakeaways(): ?array
    {
        return $this->keyTakeaways;
    }

    public function setKeyTakeaways(?array $keyTakeaways): static
    {
        $this->keyTakeaways = $keyTakeaways;
        return $this;
    }

    public function getPracticalExercises(): ?array
    {
        return $this->practicalExercises;
    }

    public function setPracticalExercises(?array $practicalExercises): static
    {
        $this->practicalExercises = $practicalExercises;
        return $this;
    }

    public function getFeaturedImage(): ?string
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?string $featuredImage): static
    {
        $this->featuredImage = $featuredImage;
        return $this;
    }

    public function getFeaturedImageAlt(): ?string
    {
        return $this->featuredImageAlt;
    }

    public function setFeaturedImageAlt(?string $featuredImageAlt): static
    {
        $this->featuredImageAlt = $featuredImageAlt;
        return $this;
    }

    public function getTableOfContents(): ?array
    {
        return $this->tableOfContents;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[Groups(['guide_chapter:read', 'guide:read'])]
    public function getChapterNumber(): int
    {
        return $this->position + 1;
    }
}
