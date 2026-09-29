<?php

declare(strict_types=1);

namespace App\Profile;

use App\Entity\Profile\MemberProfile;
use App\Entity\User;

final class ProfileVisibilityPolicy
{
    public function canView(MemberProfile $profile, string $field, ?User $viewer): bool
    {
        if ($this->sameUser($profile->getUser(), $viewer)) {
            return true;
        }

        return match ($profile->visibilityFor($field)) {
            MemberProfile::VISIBILITY_PUBLIC => true,
            MemberProfile::VISIBILITY_MEMBERS => $viewer instanceof User && $viewer->isActive() && !$viewer->isLocked(),
            MemberProfile::VISIBILITY_PRIVATE => false,
            default => false,
        };
    }

    private function sameUser(User $owner, ?User $viewer): bool
    {
        if (!$viewer instanceof User) {
            return false;
        }

        $ownerId = $owner->getId();
        $viewerId = $viewer->getId();

        return $owner === $viewer || ($ownerId !== null && $viewerId !== null && $ownerId === $viewerId);
    }
}
