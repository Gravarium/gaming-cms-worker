<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

final class GuildFeatureGate
{
    public function allows(bool $moduleEnabled, int $actorGuildId, int $targetGuildId, bool $authorized): bool
    {
        return $moduleEnabled
            && $actorGuildId > 0
            && $targetGuildId > 0
            && $actorGuildId === $targetGuildId
            && $authorized;
    }
}
