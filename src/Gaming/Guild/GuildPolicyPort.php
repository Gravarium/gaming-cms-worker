<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

/**
 * Public replaceable seam for future Fortress policy evaluation.
 * Implementations must fail closed when context is incomplete or unavailable.
 */
interface GuildPolicyPort
{
    public function allows(string $action, int $actorUserId, int $guildId, int $resourceGuildId): bool;
}
