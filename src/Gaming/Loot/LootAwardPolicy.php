<?php

declare(strict_types=1);

namespace App\Gaming\Loot;

final class LootAwardPolicy
{
    /** @param array{dkp?: int, ep?: int, gp?: int} $balances */
    public function score(string $strategy, array $balances, int $wishlistPriority = 0, int $roll = 0): float
    {
        return match ($strategy) {
            'dkp' => (float) ($balances['dkp'] ?? 0),
            'epgp' => (float) ($balances['ep'] ?? 0) / max(1, $balances['gp'] ?? 0),
            'wishlist' => (float) $wishlistPriority,
            'roll' => $roll >= 1 && $roll <= 100 ? (float) $roll : throw new \InvalidArgumentException('Roll must be between 1 and 100.'),
            'loot_council' => throw new \DomainException('Loot-council awards require an explicit officer decision and audit reason.'),
            default => throw new \InvalidArgumentException('Unsupported loot strategy.'),
        };
    }
}
