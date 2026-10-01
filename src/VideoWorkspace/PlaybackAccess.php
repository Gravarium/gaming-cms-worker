<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoWorkspace\VideoSource;
use App\Video\Discovery\VideoVisibilityPolicy;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlaybackAccess
{
    public function __construct(private EntityManagerInterface $em, private VideoVisibilityPolicy $visibility) {}
    public function video(Video $video, ?User $viewer): bool
    {
        if (!$video->isPublished()) { return false; }
        $profile = $this->em->getRepository(VideoDiscoveryProfile::class)->findOneBy(['video' => $video]);
        return !$profile instanceof VideoDiscoveryProfile || $this->visibility->canViewProfile($profile, $viewer);
    }
    public function source(VideoSource $source, ?User $viewer): bool
    {
        if (!$source->isEnabled() || !$source->isAuthorized()) { return false; }
        if ($source->getVideo() instanceof Video && !$this->video($source->getVideo(), $viewer)) { return false; }
        $creator = $source->getCreator();
        return $creator === null || $this->visibility->canViewCreator($creator, $viewer);
    }
}
