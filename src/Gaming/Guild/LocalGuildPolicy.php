<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

final class LocalGuildPolicy implements GuildPolicyPort
{
    public function allows(string $action, int $actorUserId, int $guildId, int $resourceGuildId): bool
    {
        return trim($action) !== ''
            && $actorUserId > 0
            && $guildId > 0
            && $resourceGuildId > 0
            && $guildId === $resourceGuildId;
    }
}
