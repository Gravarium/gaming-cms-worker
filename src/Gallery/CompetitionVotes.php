<?php

declare(strict_types=1);

namespace App\Gallery;

final class CompetitionVotes
{
    /** @var array<int, int> */
    private array $votesByUser = [];
    /** @var list<array{userId: int, fromSubmissionId: int|null, toSubmissionId: int|null, actorId: int, reason: string}> */
    private array $audit = [];

    public function cast(int $userId, int $submissionId): void
    {
        if ($userId < 1 || $submissionId < 1 || isset($this->votesByUser[$userId])) {
            throw new \DomainException('One-account competition vote limit enforced.');
        }
        $this->votesByUser[$userId] = $submissionId;
        $this->audit[] = ['userId' => $userId, 'fromSubmissionId' => null, 'toSubmissionId' => $submissionId, 'actorId' => $userId, 'reason' => 'vote_cast'];
    }

    public function correct(int $userId, ?int $replacementSubmissionId, int $moderatorId, string $reason): void
    {
        $current = $this->votesByUser[$userId] ?? null;
        if ($current === null || $moderatorId < 1 || $moderatorId === $userId || trim($reason) === '') {
            throw new \DomainException('Audited independent vote correction required.');
        }
        if ($replacementSubmissionId === null) {
            unset($this->votesByUser[$userId]);
        } elseif ($replacementSubmissionId < 1) {
            throw new \InvalidArgumentException('Invalid replacement submission.');
        } else {
            $this->votesByUser[$userId] = $replacementSubmissionId;
        }
        $this->audit[] = ['userId' => $userId, 'fromSubmissionId' => $current, 'toSubmissionId' => $replacementSubmissionId, 'actorId' => $moderatorId, 'reason' => $reason];
    }

    public function countFor(int $submissionId): int
    {
        return count(array_filter($this->votesByUser, static fn (int $candidate): bool => $candidate === $submissionId));
    }

    /** @return list<array{userId: int, fromSubmissionId: int|null, toSubmissionId: int|null, actorId: int, reason: string}> */
    public function auditTrail(): array
    {
        return $this->audit;
    }
}
