<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

final class RosterPrivacyPolicy
{
    public function canViewGuildField(int $actorGuildId, int $recordGuildId, bool $privateField, bool $officer): bool
    {
        if ($actorGuildId < 1 || $recordGuildId < 1 || $actorGuildId !== $recordGuildId) {
            return false;
        }

        return !$privateField || $officer;
    }

    public function canMutateGuildRecord(int $actorGuildId, int $recordGuildId, bool $officer): bool
    {
        return $actorGuildId > 0 && $actorGuildId === $recordGuildId && $officer;
    }
}
