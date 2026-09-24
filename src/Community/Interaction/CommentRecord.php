<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final class CommentRecord
{
    public const MAX_DEPTH = 4;
    public const MAX_BODY_LENGTH = 10000;

    private ?\DateTimeImmutable $deletedAt = null;
    private ?int $deletedByUserId = null;
    private ?string $deletionReason = null;

    public function __construct(
        private readonly string $targetType,
        private readonly int $targetId,
        private readonly int $authorUserId,
        private string $body,
        private readonly ?self $parent = null,
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        if ($targetType === '' || $targetId < 1 || $authorUserId < 1) {
            throw new \InvalidArgumentException('Comment identity is invalid.');
        }
        $this->body = trim($this->body);
        if ($this->body === '' || mb_strlen($this->body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException('Comment body is empty or too long.');
        }
        if ($parent !== null) {
            if ($parent->targetType !== $targetType || $parent->targetId !== $targetId) {
                throw new \InvalidArgumentException('Reply parent must belong to the same target.');
            }
            if ($parent->depth() >= self::MAX_DEPTH) {
                throw new \DomainException('Maximum comment nesting depth reached.');
            }
        }
    }

    public function depth(): int
    {
        return $this->parent === null ? 0 : $this->parent->depth() + 1;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function softDelete(int $actorUserId, string $reason, \DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $reason = trim($reason);
        if ($actorUserId < 1 || $reason === '') {
            throw new \InvalidArgumentException('Deletion requires actor and reason.');
        }
        if ($this->deletedAt !== null) {
            throw new \DomainException('Comment is already deleted.');
        }
        $this->deletedAt = $at;
        $this->deletedByUserId = $actorUserId;
        $this->deletionReason = $reason;
    }

    public function restore(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new \InvalidArgumentException('Restoration requires actor.');
        }
        if ($this->deletedAt === null) {
            throw new \DomainException('Only deleted comments can be restored.');
        }
        $this->deletedAt = null;
        $this->deletedByUserId = null;
        $this->deletionReason = null;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function deletedByUserId(): ?int
    {
        return $this->deletedByUserId;
    }

    public function deletionReason(): ?string
    {
        return $this->deletionReason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
