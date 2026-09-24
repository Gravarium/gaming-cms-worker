<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final class KnowledgeVisibilityPolicy
{
    public function canView(string $visibility, bool $moduleEnabled, bool $isMember, bool $isOwner): bool
    {
        if (!$moduleEnabled) {
            return false;
        }

        return match ($visibility) {
            'public' => true,
            'members' => $isMember,
            'private' => $isOwner,
            default => false,
        };
    }
}
