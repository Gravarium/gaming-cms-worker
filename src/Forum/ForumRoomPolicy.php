<?php

declare(strict_types=1);

namespace App\Forum;

final class ForumRoomPolicy
{
    public function canView(
        string $visibility,
        ?int $roomGuildId,
        ?int $viewerGuildId,
        bool $isAuthenticated,
        bool $isModerator,
        bool $moduleEnabled,
    ): bool {
        if (!$moduleEnabled) {
            return false;
        }

        return match ($visibility) {
            'public' => true,
            'members' => $isAuthenticated,
            'guild' => $isModerator || ($roomGuildId !== null && $roomGuildId === $viewerGuildId),
            'moderators' => $isModerator,
            default => false,
        };
    }
}
