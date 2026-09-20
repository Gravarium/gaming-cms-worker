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
        $this->slug = $entry->getSlug();
        $this->excerpt = $entry->getExcerpt();
        $this->body = $entry->getBody();
        $this->status = $entry->getStatus();
        $this->publishedAt = $entry->getPublishedAt();
        $this->scheduledAt = $entry->getScheduledAt();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntry(): ContentEntry { return $this->entry; }
    public function getRevisionNumber(): int { return $this->revisionNumber; }
    public function getTitle(): string { return $this->title; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function restoreTo(ContentEntry $entry): void
    {
        if ($entry !== $this->entry) { throw new \DomainException('Revision belongs to another content entry.'); }
        $entry->setType($this->type)->setTitle($this->title)->setSlug($this->slug)
            ->setExcerpt($this->excerpt)->setBody($this->body)->setStatus($this->status)
            ->setPublishedAt($this->publishedAt)->setScheduledAt($this->scheduledAt);
    }
}
