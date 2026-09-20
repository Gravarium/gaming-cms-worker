<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContentEntryRepository::class)]
#[ORM\Table(name: 'content_entry')]
#[ORM\UniqueConstraint(name: 'uniq_content_entry_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'])]
class ContentEntry
{
    public const TYPE_PAGE = 'page';
    public const TYPE_NEWS = 'news';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_PAGE, self::TYPE_NEWS])]
    private string $type = self::TYPE_NEWS;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $title = '';

    #[ORM\Column(length: 200)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_REVIEW, self::STATUS_SCHEDULED, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $author = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getExcerpt(): ?string { return $this->excerpt; }
    public function setExcerpt(?string $excerpt): self
    {
        $excerpt = $excerpt === null ? null : trim($excerpt);
        $this->excerpt = $excerpt === '' ? null : $excerpt;
        return $this;
    }
    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = trim($body); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self { $this->publishedAt = $publishedAt; return $this; }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(User $author): self { $this->author = $author; return $this; }
    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): self { $this->category = $category; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->publishedAt !== null && $this->publishedAt <= new \DateTimeImmutable();
    }

    public function synchronizePublication(): void
    {
        if ($this->status === self::STATUS_PUBLISHED) {
            $this->publishedAt ??= new \DateTimeImmutable();
            $this->scheduledAt = null;
            return;
        }
        if ($this->status === self::STATUS_SCHEDULED) {
            if ($this->scheduledAt === null || $this->scheduledAt <= new \DateTimeImmutable()) {
                throw new \DomainException('Geplante Veröffentlichungen benötigen einen zukünftigen Zeitpunkt.');
            }
            $this->publishedAt = null;
            return;
        }
        $this->publishedAt = null;
        $this->scheduledAt = null;
    }

    public function publishIfDue(\DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED || $this->scheduledAt === null || $this->scheduledAt > $now) {
            return false;
        }
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = $this->scheduledAt;
        $this->scheduledAt = null;
        return true;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
