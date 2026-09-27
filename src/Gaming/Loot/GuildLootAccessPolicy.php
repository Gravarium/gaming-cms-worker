<?php

declare(strict_types=1);

namespace App\Gaming\Loot;

final class GuildLootAccessPolicy
{
    public function canApprove(int $resourceGuildId, int $actorGuildId, bool $isOfficer, bool $moduleEnabled): bool
    {
        return $moduleEnabled && $isOfficer && $resourceGuildId > 0 && $resourceGuildId === $actorGuildId;
    }
}
