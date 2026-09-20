<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentRevisionRepository::class)]
#[ORM\Table(name: 'content_revision')]
#[ORM\UniqueConstraint(name: 'uniq_content_revision_number', columns: ['entry_id', 'revision_number'])]
class ContentRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ContentEntry $entry;
    #[ORM\Column]
    private int $revisionNumber;
    #[ORM\Column(length: 20)]
    private string $type;
    #[ORM\Column(length: 180)]
    private string $title;
    #[ORM\Column(length: 220, nullable: true)]
    private ?string $subtitle;
    #[ORM\Column(length: 200)]
    private string $slug;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt;
    #[ORM\Column(type: Types::TEXT)]
    private string $body;
    #[ORM\Column(length: 20)]
    private string $status;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledUnpublishAt;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category;
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tagSlugs = [];
    #[ORM\Column(options: ['default' => false])]
    private bool $featured;
    #[ORM\Column(options: ['default' => false])]
    private bool $pinned;
    #[ORM\Column(options: ['default' => false])]
    private bool $unlisted;
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $seoTitle;
    #[ORM\Column(length: 320, nullable: true)]
    private ?string $seoDescription;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $canonicalUrl;
    #[ORM\Column(options: ['default' => false])]
    private bool $noIndex;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ContentEntry $entry, int $number, User $createdBy)
    {
        if ($number < 1) { throw new \InvalidArgumentException('Revision number must be positive.'); }
        $this->entry = $entry;
        $this->revisionNumber = $number;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->type = $entry->getType();
        $this->title = $entry->getTitle();
        $this->subtitle = $entry->getSubtitle();
        $this->slug = $entry->getSlug();
        $this->excerpt = $entry->getExcerpt();
        $this->body = $entry->getBody();
        $this->status = $entry->getStatus();
        $this->publishedAt = $entry->getPublishedAt();
        $this->scheduledAt = $entry->getScheduledAt();
        $this->scheduledUnpublishAt = $entry->getScheduledUnpublishAt();
        $this->category = $entry->getCategory();
        $this->tagSlugs = array_values(array_map(static fn (ContentTag $tag): string => $tag->getSlug(), $entry->getTags()->toArray()));
        $this->featured = $entry->isFeatured();
        $this->pinned = $entry->isPinned();
        $this->unlisted = $entry->isUnlisted();
        $this->seoTitle = $entry->getSeoTitle();
        $this->seoDescription = $entry->getSeoDescription();
        $this->canonicalUrl = $entry->getCanonicalUrl();
        $this->noIndex = $entry->isNoIndex();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntry(): ContentEntry { return $this->entry; }
    public function getRevisionNumber(): int { return $this->revisionNumber; }
    public function getTitle(): string { return $this->title; }
    public function getSubtitle(): ?string { return $this->subtitle; }
    public function getSlug(): string { return $this->slug; }
    public function getExcerpt(): ?string { return $this->excerpt; }
    public function getBody(): string { return $this->body; }
    public function getStatus(): string { return $this->status; }
    public function getCategory(): ?Category { return $this->category; }
    /** @return list<string> */
    public function getTagSlugs(): array { return $this->tagSlugs; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function restoreTo(ContentEntry $entry): void
    {
        if ($entry !== $this->entry) { throw new \DomainException('Revision belongs to another content entry.'); }
        $entry->setType($this->type)->setTitle($this->title)->setSubtitle($this->subtitle)->setSlug($this->slug)
            ->setExcerpt($this->excerpt)->setBody($this->body)->setStatus($this->status)
            ->setPublishedAt($this->publishedAt)->setScheduledAt($this->scheduledAt)
            ->setScheduledUnpublishAt($this->scheduledUnpublishAt)->setCategory($this->category)
            ->setFeatured($this->featured)->setPinned($this->pinned)->setUnlisted($this->unlisted)
            ->setSeoTitle($this->seoTitle)->setSeoDescription($this->seoDescription)
            ->setCanonicalUrl($this->canonicalUrl)->setNoIndex($this->noIndex);
    }
}
