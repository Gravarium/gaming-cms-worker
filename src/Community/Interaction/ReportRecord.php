<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final class ReportRecord
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';
    public const REASONS = ['spam', 'abuse', 'harassment', 'illegal', 'privacy', 'other'];

    private string $status = self::STATUS_OPEN;
    private ?int $decidedByUserId = null;
    private ?string $decisionReason = null;
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(
        public readonly string $targetKind,
        public readonly int $targetId,
        public readonly int $reporterUserId,
        public readonly string $reason,
        public readonly ?string $details = null,
    ) {
        if ($targetKind === '' || $targetId < 1 || $reporterUserId < 1 || !in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Invalid report.');
        }
        if ($details !== null && mb_strlen(trim($details)) > 2000) {
            throw new \InvalidArgumentException('Report details are too long.');
        }
    }

    public function status(): string
    {
        return $this->status;
    }

    public function startReview(): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new \DomainException('Only open reports can enter review.');
        }
        $this->status = self::STATUS_REVIEWING;
    }

    public function decide(bool $upheld, int $moderatorUserId, string $reason, \DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $reason = trim($reason);
        if ($this->status !== self::STATUS_REVIEWING || $moderatorUserId < 1 || $reason === '') {
            throw new \DomainException('Report decision requires active review, moderator and reason.');
        }
        $this->status = $upheld ? self::STATUS_RESOLVED : self::STATUS_REJECTED;
        $this->decidedByUserId = $moderatorUserId;
        $this->decisionReason = $reason;
        $this->decidedAt = $at;
    }

    public function decidedByUserId(): ?int
    {
        return $this->decidedByUserId;
    }

    public function decisionReason(): ?string
    {
        return $this->decisionReason;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
