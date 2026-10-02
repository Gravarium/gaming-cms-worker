<?php

declare(strict_types=1);

namespace App\VideoPlaybackJourney;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoWorkspace\VideoSource;
use App\Service\VideoEmbedResolver;
use App\Video\Discovery\VideoVisibilityPolicy;
use App\VideoWorkspace\PlaybackAccess;
use App\VideoWorkspace\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ViewingSources
{
    public function __construct(private EntityManagerInterface $em, private PlaybackAccess $access,
        private VideoVisibilityPolicy $visibility, private ProviderRegistry $providers, private VideoEmbedResolver $legacy) {}

    public function visible(Video $video, ?User $viewer): bool
    {
        if (!$this->access->video($video, $viewer)) { return false; }
        $creator = $this->profile($video)?->getCreator();
        return $creator === null || $this->visibility->canViewCreator($creator, $viewer);
    }

    public function publicVideo(Video $video): bool
    {
        return $this->visible($video, null);
    }

    /** @return array<int|string,array{label:string,playback:array{mode:string,url:string}|null,resumable:bool}> */
    public function entries(Video $video, ?User $viewer, string $host): array
    {
        $entries = [];
        $original = $this->original($video, $host);
        if ($original !== null) { $entries['legacy'] = ['label' => 'Originalquelle', 'playback' => $original, 'resumable' => $this->publicVideo($video)]; }
        foreach ($this->em->getRepository(VideoSource::class)->findBy(['video' => $video, 'enabled' => true, 'authorized' => true], ['position' => 'ASC', 'id' => 'ASC'], 100) as $source) {
            if (!$this->access->source($source, $viewer)) { continue; }
            $entries[(string) $source->getId()] = ['label' => $source->getLabel(),
                'playback' => $this->providers->resolve($source->getProvider(), $source->getUrl(), $host),
                'resumable' => $this->publicVideo($video) && ($source->getCreator() === null || $this->visibility->canViewCreator($source->getCreator(), null))];
        }
        return $entries;
    }

    /** @return array{mode:string,url:string}|null */
    public function original(Video $video, string $host): ?array
    {
        if ($video->getSourceType() === Video::SOURCE_EXTERNAL) {
            foreach (['mp4', 'webm', 'hls'] as $format) {
                $playback = $this->providers->resolve($format, $video->getSourceUrl() ?? '', $host);
                if ($playback !== null) { return $playback; }
            }
            // An arbitrary provider page is never treated as a direct video.
            return null;
        }
        return $this->legacy->resolve($video, $host);
    }

    private function profile(Video $video): ?VideoDiscoveryProfile
    {
        $profile = $this->em->getRepository(VideoDiscoveryProfile::class)->findOneBy(['video' => $video]);
        return $profile instanceof VideoDiscoveryProfile ? $profile : null;
    }
}
