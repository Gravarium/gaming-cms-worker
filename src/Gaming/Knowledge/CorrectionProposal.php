<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final class CorrectionProposal
{
    private string $status = 'pending';
    private ?int $reviewerId = null;
    private ?string $decisionReason = null;

    public function __construct(
        public readonly int $gameId,
        public readonly string $recordKey,
        public readonly int $proposerId,
        public readonly string $proposal,
        public readonly string $source,
        public readonly string $license,
    ) {
        if ($gameId < 1 || $proposerId < 1 || trim($proposal) === '' || trim($source) === '' || trim($license) === '') {
            throw new \InvalidArgumentException('Correction proposal and provenance are required.');
        }
    }

    public function decide(bool $accepted, int $reviewerId, string $reason): void
    {
        if ($this->status !== 'pending' || $reviewerId < 1 || $reviewerId === $this->proposerId || trim($reason) === '') {
            throw new \DomainException('Independent moderated correction decision required.');
        }
        $this->status = $accepted ? 'accepted' : 'rejected';
        $this->reviewerId = $reviewerId;
        $this->decisionReason = $reason;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array{reviewerId: int|null, reason: string|null} */
    public function decisionEvidence(): array
    {
        return ['reviewerId' => $this->reviewerId, 'reason' => $this->decisionReason];
    }
}
