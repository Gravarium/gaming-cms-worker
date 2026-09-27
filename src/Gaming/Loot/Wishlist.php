<?php

declare(strict_types=1);

namespace App\Gaming\Loot;

final class Wishlist
{
    /** @var array<string, int> */
    private array $items = [];

    public function __construct(public readonly int $guildId, public readonly int $memberId)
    {
        if ($guildId < 1 || $memberId < 1) {
            throw new \InvalidArgumentException('Guild and member are required.');
        }
    }

    public function wish(string $itemKey, int $priority): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $itemKey) || $priority < 1 || $priority > 5) {
            throw new \InvalidArgumentException('Invalid wishlist item or priority.');
        }
        if (!isset($this->items[$itemKey]) && count($this->items) >= 100) {
            throw new \DomainException('Wishlist limit reached.');
        }
        $this->items[$itemKey] = $priority;
    }

    public function priority(string $itemKey): ?int
    {
        return $this->items[$itemKey] ?? null;
    }
}
