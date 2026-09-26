<?php

declare(strict_types=1);

namespace App\Entity\Community;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'community_comment')]
#[ORM\Index(name: 'idx_community_comment_target', columns: ['target_type', 'target_id', 'created_at'])]
class CommunityComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $targetType;

    #[ORM\Column]
    private int $targetId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?self $parent = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $deletionReason = null;

    public function __construct(string $targetType, int $targetId, User $author, string $body, ?self $parent = null)
    {
        $targetType = trim($targetType);
        $body = trim($body);
        if ($targetType === '' || $targetId < 1 || $body === '' || mb_strlen($body) > 10000) {
            throw new \InvalidArgumentException('Invalid community comment.');
        }
        if ($parent !== null) {
            if ($parent->targetType !== $targetType || $parent->targetId !== $targetId) {
                throw new \InvalidArgumentException('Reply parent must use the same interaction target.');
            }
            if ($parent->depth() >= 4) {
                throw new \DomainException('Maximum comment nesting depth reached.');
            }
        }

        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->author = $author;
        $this->body = $body;
        $this->parent = $parent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetId(): int { return $this->targetId; }
    public function getAuthor(): User { return $this->author; }
    public function getParent(): ?self { return $this->parent; }
    public function getBody(): string { return $this->body; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getDeletionReason(): ?string { return $this->deletionReason; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    public function depth(): int
    {
        return $this->parent === null ? 0 : $this->parent->depth() + 1;
    }

    public function softDelete(User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Deletion reason is required.');
        }
        if ($this->isDeleted()) {
            throw new \DomainException('Comment is already deleted.');
        }
        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedBy = $actor;
        $this->deletionReason = $reason;
    }

    public function restore(): void
    {
        if (!$this->isDeleted()) {
            throw new \DomainException('Only deleted comments can be restored.');
        }
        $this->deletedAt = null;
        $this->deletedBy = null;
        $this->deletionReason = null;
    }
}
