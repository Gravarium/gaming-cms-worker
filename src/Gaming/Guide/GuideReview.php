<?php

declare(strict_types=1);

namespace App\Gaming\Guide;

final class GuideReview
{
    private string $status = 'draft';
    /** @var list<array{status: string, actorId: int, reason: string, at: \DateTimeImmutable}> */
    private array $history = [];

    public function submit(int $authorId, \DateTimeImmutable $at): void
    {
        $this->transition('draft', 'review', $authorId, 'Submitted for review', $at);
    }

    public function approve(int $reviewerId, int $authorId, string $reason, \DateTimeImmutable $at): void
    {
        if ($reviewerId === $authorId) {
            throw new \DomainException('Authors cannot approve their own guide.');
        }
        $this->transition('review', 'published', $reviewerId, $reason, $at);
    }

    public function reject(int $reviewerId, string $reason, \DateTimeImmutable $at): void
    {
        $this->transition('review', 'draft', $reviewerId, $reason, $at);
    }

    private function transition(string $from, string $to, int $actorId, string $reason, \DateTimeImmutable $at): void
    {
        if ($this->status !== $from || $actorId < 1 || trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new \DomainException('Invalid guide review transition.');
        }
        $previous = $this->history === [] ? null : $this->history[count($this->history) - 1];
        if ($previous !== null && $at < $previous['at']) {
            throw new \DomainException('Review history must remain chronological.');
        }
        $this->status = $to;
        $this->history[] = ['status' => $to, 'actorId' => $actorId, 'reason' => $reason, 'at' => $at];
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<array{status: string, actorId: int, reason: string, at: \DateTimeImmutable}> */
    public function history(): array
    {
        return $this->history;
    }
}
