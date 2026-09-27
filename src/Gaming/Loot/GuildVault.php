<?php

declare(strict_types=1);

namespace App\Gaming\Loot;

final class GuildVault
{
    /** @var array<string, int> */
    private array $stock = [];
    /** @var list<array{item: string, delta: int, actorId: int, reason: string}> */
    private array $audit = [];

    public function __construct(public readonly int $guildId)
    {
        if ($guildId < 1) {
            throw new \InvalidArgumentException('Guild is required.');
        }
    }

    public function adjust(string $itemKey, int $delta, int $actorId, string $reason): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $itemKey) || $delta === 0 || $actorId < 1 || $reason === '') {
            throw new \InvalidArgumentException('Complete vault audit evidence is required.');
        }
        $next = ($this->stock[$itemKey] ?? 0) + $delta;
        if ($next < 0 || $next > 1_000_000) {
            throw new \DomainException('Vault inventory bounds exceeded.');
        }
        $this->stock[$itemKey] = $next;
        $this->audit[] = ['item' => $itemKey, 'delta' => $delta, 'actorId' => $actorId, 'reason' => $reason];
    }

    public function quantity(string $itemKey): int
    {
        return $this->stock[$itemKey] ?? 0;
    }

    /** @return list<array{item: string, delta: int, actorId: int, reason: string}> */
    public function auditTrail(): array
    {
        return $this->audit;
    }
}
