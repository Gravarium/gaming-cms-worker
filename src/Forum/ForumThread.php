<?php

declare(strict_types=1);

namespace App\Forum;

final class ForumThread
{
    private string $state = 'open';
    private int $version = 0;
    private ?int $solvedPostId = null;
    /** @var list<array{state: string, actorId: int, reason: string, at: \DateTimeImmutable}> */
    private array $moderationHistory = [];

    public function __construct(public readonly int $authorId, public readonly string $title)
    {
        if ($authorId < 1 || trim($title) === '' || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Thread author and bounded title are required.');
        }
    }

    public function transition(string $state, int $actorId, string $reason, int $expectedVersion, \DateTimeImmutable $at): void
    {
        if ($expectedVersion !== $this->version) {
            throw new \DomainException('Concurrent thread update detected.');
        }
        if (!in_array($state, ['open', 'locked', 'archived'], true) || $actorId < 1 || trim($reason) === '') {
            throw new \DomainException('Invalid audited thread transition.');
        }
        $this->state = $state;
        ++$this->version;
        $this->moderationHistory[] = ['state' => $state, 'actorId' => $actorId, 'reason' => $reason, 'at' => $at];
    }

    public function markSolved(int $postId, int $actorId, int $expectedVersion): void
    {
        if ($expectedVersion !== $this->version || $postId < 1 || $actorId !== $this->authorId || $this->state !== 'open') {
            throw new \DomainException('Only the thread author may solve an open thread at the current version.');
        }
        $this->solvedPostId = $postId;
        ++$this->version;
    }

    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
    public function solvedPostId(): ?int { return $this->solvedPostId; }

    /** @return list<array{state: string, actorId: int, reason: string, at: \DateTimeImmutable}> */
    public function moderationHistory(): array { return $this->moderationHistory; }
}
