<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final class PrivateChecklist
{
    /** @var array<string, bool> */
    private array $items = [];

    public function __construct(public readonly int $gameId, public readonly int $ownerId)
    {
        if ($gameId < 1 || $ownerId < 1) {
            throw new \InvalidArgumentException('Checklist game and owner are required.');
        }
    }

    public function set(string $key, bool $completed, int $actorId): void
    {
        if ($actorId !== $this->ownerId) {
            throw new \DomainException('Private checklist access denied.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $key)) {
            throw new \InvalidArgumentException('Invalid checklist key.');
        }
        if (!isset($this->items[$key]) && count($this->items) >= 5_000) {
            throw new \DomainException('Checklist limit reached.');
        }
        $this->items[$key] = $completed;
    }

    public function isCompleted(string $key, int $viewerId): bool
    {
        if ($viewerId !== $this->ownerId) {
            throw new \DomainException('Private checklist access denied.');
        }

        return $this->items[$key] ?? false;
    }
}
