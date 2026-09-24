<?php

declare(strict_types=1);

namespace App\Gallery;

final class GalleryVisibilityPolicy
{
    public function canView(string $visibility, bool $moduleEnabled, bool $isMember, bool $isOwnerOrModerator): bool
    {
        if (!$moduleEnabled) {
            return false;
        }

        return match ($visibility) {
            'public' => true,
            'members' => $isMember,
            'private' => $isOwnerOrModerator,
            default => false,
        };
    }
}
