<?php

declare(strict_types=1);

namespace App\Profile;

use App\Entity\Profile\MemberProfile;
use App\Entity\User;

final class ProfileVisibilityPolicy
{
    public function canView(MemberProfile $profile, string $field, ?User $viewer): bool
    {
        if ($viewer === $profile->getUser()) {
            return true;
        }

        return match ($profile->visibilityFor($field)) {
            MemberProfile::VISIBILITY_PUBLIC => true,
            MemberProfile::VISIBILITY_MEMBERS => $viewer instanceof User && $viewer->isActive(),
            MemberProfile::VISIBILITY_PRIVATE => false,
            default => false,
        };
    }
}
