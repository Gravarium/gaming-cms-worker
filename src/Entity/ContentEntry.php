<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    public const STATUS_TRASHED = 'trashed';

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

    #[ORM\Column(length: 220, nullable: true)]
    #[Assert\Length(max: 220)]
    private ?string $subtitle = null;

    #[ORM\Column(length: 200)]
    #[Assert\Regex(pattern: '/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/', message: 'Der Slug darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.')]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_REVIEW, self::STATUS_SCHEDULED, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED, self::STATUS_TRASHED])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledUnpublishAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $unlisted = false;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    private ?string $seoTitle = null;

    #[ORM\Column(length: 320, nullable: true)]
    #[Assert\Length(max: 320)]
    private ?string $seoDescription = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
    #[Assert\Length(max: 500)]
    private ?string $canonicalUrl = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $noIndex = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $author = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Category $category = null;

    /** @var Collection<int, ContentTag> */
    #[ORM\ManyToMany(targetEntity: ContentTag::class, inversedBy: 'entries')]
    #[ORM\JoinTable(name: 'content_entry_tag')]
    private Collection $tags;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getSubtitle(): ?string { return $this->subtitle; }
    public function setSubtitle(?string $subtitle): self { $this->subtitle = $this->normalizeNullable($subtitle); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = trim(mb_strtolower($slug)); return $this; }
    public function getExcerpt(): ?string { return $this->excerpt; }
    public function setExcerpt(?string $excerpt): self { $this->excerpt = $this->normalizeNullable($excerpt); return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = trim($body); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self { $this->publishedAt = $publishedAt; return $this; }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }
    public function getScheduledUnpublishAt(): ?\DateTimeImmutable { return $this->scheduledUnpublishAt; }
    public function setScheduledUnpublishAt(?\DateTimeImmutable $at): self { $this->scheduledUnpublishAt = $at; return $this; }
    public function getTrashedAt(): ?\DateTimeImmutable { return $this->trashedAt; }
    public function isFeatured(): bool { return $this->featured; }
    public function setFeatured(bool $featured): self { $this->featured = $featured; return $this; }
    public function isPinned(): bool { return $this->pinned; }
    public function setPinned(bool $pinned): self { $this->pinned = $pinned; return $this; }
    public function isUnlisted(): bool { return $this->unlisted; }
    public function setUnlisted(bool $unlisted): self { $this->unlisted = $unlisted; return $this; }
    public function getSeoTitle(): ?string { return $this->seoTitle; }
    public function setSeoTitle(?string $seoTitle): self { $this->seoTitle = $this->normalizeNullable($seoTitle); return $this; }
    public function getSeoDescription(): ?string { return $this->seoDescription; }
    public function setSeoDescription(?string $seoDescription): self { $this->seoDescription = $this->normalizeNullable($seoDescription); return $this; }
    public function getCanonicalUrl(): ?string { return $this->canonicalUrl; }
    public function setCanonicalUrl(?string $canonicalUrl): self { $this->canonicalUrl = $this->normalizeNullable($canonicalUrl); return $this; }
    public function isNoIndex(): bool { return $this->noIndex; }
    public function setNoIndex(bool $noIndex): self { $this->noIndex = $noIndex; return $this; }
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(User $author): self { $this->author = $author; return $this; }
    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $category): self { $this->category = $category; return $this; }
    /** @return Collection<int, ContentTag> */
    public function getTags(): Collection { return $this->tags; }
    public function addTag(ContentTag $tag): self { if (!$this->tags->contains($tag)) { $this->tags->add($tag); } return $this; }
    public function removeTag(ContentTag $tag): self { $this->tags->removeElement($tag); return $this; }
    public function clearTags(): self { $this->tags->clear(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->publishedAt !== null && $this->publishedAt <= new \DateTimeImmutable();
    }

    public function isPubliclyListed(): bool { return $this->isPublished() && !$this->unlisted; }

    public function synchronizePublication(): void
    {
        $now = new \DateTimeImmutable();
        if ($this->status === self::STATUS_PUBLISHED) {
            $this->publishedAt ??= $now;
            $this->scheduledAt = null;
            $this->trashedAt = null;
            if ($this->scheduledUnpublishAt !== null && $this->scheduledUnpublishAt <= $now) {
                throw new \DomainException('Das geplante Ende der Veröffentlichung muss in der Zukunft liegen.');
            }
            return;
        }
        if ($this->status === self::STATUS_SCHEDULED) {
            if ($this->scheduledAt === null || $this->scheduledAt <= $now) {
                throw new \DomainException('Geplante Veröffentlichungen benötigen einen zukünftigen Zeitpunkt.');
            }
            if ($this->scheduledUnpublishAt !== null && $this->scheduledUnpublishAt <= $this->scheduledAt) {
                throw new \DomainException('Das geplante Ende muss nach der geplanten Veröffentlichung liegen.');
            }
            $this->publishedAt = null;
            $this->trashedAt = null;
            return;
        }
        if ($this->status === self::STATUS_TRASHED) {
            $this->trashedAt ??= $now;
            $this->publishedAt = null;
            $this->scheduledAt = null;
            $this->scheduledUnpublishAt = null;
            return;
        }
        $this->publishedAt = null;
        $this->scheduledAt = null;
        $this->scheduledUnpublishAt = null;
        $this->trashedAt = null;
    }

    public function publishIfDue(\DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED || $this->scheduledAt === null || $this->scheduledAt > $now) { return false; }
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt = $this->scheduledAt;
        $this->scheduledAt = null;
        $this->trashedAt = null;
        return true;
    }

    public function unpublishIfDue(\DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_PUBLISHED || $this->scheduledUnpublishAt === null || $this->scheduledUnpublishAt > $now) { return false; }
        $this->status = self::STATUS_ARCHIVED;
        $this->scheduledUnpublishAt = null;
        return true;
    }

    public function trash(): void
    {
        $this->status = self::STATUS_TRASHED;
        $this->publishedAt = null;
        $this->scheduledAt = null;
        $this->scheduledUnpublishAt = null;
        $this->trashedAt = new \DateTimeImmutable();
    }

    public function restoreFromTrash(): void
    {
        if ($this->status !== self::STATUS_TRASHED) { throw new \DomainException('Nur Inhalte im Papierkorb können wiederhergestellt werden.'); }
        $this->status = self::STATUS_DRAFT;
        $this->trashedAt = null;
    }

    public function estimatedReadingMinutes(): int
    {
        $text = trim(strip_tags($this->body));
        if ($text === '') { return 1; }
        $words = preg_split('/\s+/u', $text) ?: [];
        return max(1, (int) ceil(count($words) / 220));
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void { $this->updatedAt = new \DateTimeImmutable(); }

    private function normalizeNullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }
}
