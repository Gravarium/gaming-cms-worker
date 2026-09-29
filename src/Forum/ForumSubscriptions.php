<?php

declare(strict_types=1);

namespace App\Forum;

final class ForumSubscriptions
{
    /** @var array<int, true> */
    private array $users = [];

    public function subscribe(int $userId): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('Invalid subscriber.');
        }
        $this->users[$userId] = true;
    }

    public function unsubscribe(int $userId): void
    {
        unset($this->users[$userId]);
    }

    /** @return list<int> */
    public function recipientsExcept(int $actorId): array
    {
        return array_values(array_filter(array_keys($this->users), static fn (int $id): bool => $id !== $actorId));
    }
}
