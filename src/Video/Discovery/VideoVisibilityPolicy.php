<?php

declare(strict_types=1);

namespace App\Video\Discovery;

use App\Entity\User;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoWatchlist;

final class VideoVisibilityPolicy
{
    public function canViewProfile(VideoDiscoveryProfile $profile, ?User $viewer): bool
    {
        if (!$profile->isDiscoverable() || !$profile->getVideo()->isPublished()) {
            return false;
        }

        return match ($profile->getVisibility()) {
            VideoDiscoveryProfile::VISIBILITY_PUBLIC => true,
            VideoDiscoveryProfile::VISIBILITY_MEMBER => $this->isActiveMember($viewer),
            VideoDiscoveryProfile::VISIBILITY_PRIVATE => $this->isCreatorOwner($profile->getCreator(), $viewer),
            default => false,
        };
    }

    public function canViewCreator(CreatorProfile $creator, ?User $viewer): bool
    {
        return match ($creator->getVisibility()) {
            CreatorProfile::VISIBILITY_PUBLIC => true,
            CreatorProfile::VISIBILITY_MEMBER => $this->isActiveMember($viewer),
            CreatorProfile::VISIBILITY_PRIVATE => $this->sameUser($creator->getOwner(), $viewer),
            default => false,
        };
    }

    public function canViewWatchlist(VideoWatchlist $watchlist, ?User $viewer): bool
    {
        return $watchlist->isPublic() || $this->sameUser($watchlist->getUser(), $viewer);
    }

    public function canViewClip(VideoClip $clip, ?User $viewer): bool
    {
        return match ($clip->getVisibility()) {
            'public' => true,
            'member' => $this->isActiveMember($viewer),
            'private' => $this->sameUser($clip->getCreatedBy(), $viewer),
            default => false,
        };
    }

    private function isCreatorOwner(?CreatorProfile $creator, ?User $viewer): bool
    {
        if (!$creator instanceof CreatorProfile) {
            return false;
        }

        return $this->sameUser($creator->getOwner(), $viewer);
    }

    private function isActiveMember(?User $viewer): bool
    {
        return $viewer instanceof User && $viewer->isActive() && !$viewer->isLocked();
    }

    private function sameUser(?User $owner, ?User $viewer): bool
    {
        if (!$owner instanceof User || !$viewer instanceof User) {
            return false;
        }

        $ownerId = $owner->getId();
        $viewerId = $viewer->getId();

        return $ownerId !== null && $viewerId !== null && $ownerId === $viewerId;
    }
}
