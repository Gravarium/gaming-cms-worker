<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final readonly class InteractionActor
{
    public function __construct(
        public ?int $userId,
        public bool $moderator = false,
    ) {
        if ($this->userId !== null && $this->userId < 1) {
            throw new \InvalidArgumentException('Actor user id must be positive.');
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }
}
