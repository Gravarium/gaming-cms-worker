<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoPlaylist;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoPlaylistPublicWorkflowTest extends WebTestCase
{
    public function testPublicDirectoryAndPlaylistPageExposeOnlyEnabledPublishedVideos(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $playlists = [];
        $videos = [];

        try {
            $playlist = (new VideoPlaylist())
                ->setTitle('Public playlist '.$suffix)
                ->setSlug('public-playlist-'.$suffix)
                ->setDescription('A public playlist '.$suffix);
            $hiddenPlaylist = (new VideoPlaylist())
                ->setTitle('Hidden playlist '.$suffix)
                ->setSlug('hidden-playlist-'.$suffix)
                ->setEnabled(false);
            $entityManager->persist($playlist);
            $entityManager->persist($hiddenPlaylist);
            $playlists = [$playlist, $hiddenPlaylist];

            $visibleVideo = $this->video($entityManager, $playlist, 'Visible video '.$suffix, 'visible-video-'.$suffix, new \DateTimeImmutable('-1 hour'));
            $draftVideo = $this->video($entityManager, $playlist, 'Draft video '.$suffix, 'draft-video-'.$suffix, null);
            $disabledVideo = $this->video($entityManager, $playlist, 'Disabled video '.$suffix, 'disabled-video-'.$suffix, new \DateTimeImmutable('-1 hour'));
            $disabledVideo->setEnabled(false);
            $futureVideo = $this->video($entityManager, $playlist, 'Future video '.$suffix, 'future-video-'.$suffix, new \DateTimeImmutable('+1 day'));
            $unrelatedVideo = $this->video($entityManager, null, 'Unrelated video '.$suffix, 'unrelated-video-'.$suffix, new \DateTimeImmutable('-1 hour'));
            $videos = [$visibleVideo, $draftVideo, $disabledVideo, $futureVideo, $unrelatedVideo];
            $entityManager->flush();

            $client->request('GET', '/video-playlists');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Video-Playlists');
            $directory = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($playlist->getTitle(), $directory);
            self::assertStringContainsString('/video-playlists/'.$playlist->getSlug(), $directory);
            self::assertStringNotContainsString($hiddenPlaylist->getTitle(), $directory);

            $client->request('GET', '/video-playlists/'.$playlist->getSlug());
            self::assertResponseIsSuccessful();
            $page = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($playlist->getTitle(), $page);
            self::assertStringContainsString($visibleVideo->getTitle(), $page);
            self::assertStringNotContainsString($draftVideo->getTitle(), $page);
            self::assertStringNotContainsString($disabledVideo->getTitle(), $page);
            self::assertStringNotContainsString($futureVideo->getTitle(), $page);
            self::assertStringNotContainsString($unrelatedVideo->getTitle(), $page);

            $client->request('GET', '/video-playlists/'.$hiddenPlaylist->getSlug());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures($entityManager, $videos, $playlists);
        }
    }

    public function testVideoModuleDeactivationHidesPlaylistDirectoryAndDetails(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $playlist = (new VideoPlaylist())
            ->setTitle('Module-gated playlist '.$suffix)
            ->setSlug('module-gated-playlist-'.$suffix);
        $entityManager->persist($playlist);
        $entityManager->flush();

        $state = $entityManager->find(CmsModuleState::class, 'video');
        $createdState = $state === null;
        $originalEnabled = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video');
            $entityManager->persist($state);
        }
        $state->setEnabled(false);
        $entityManager->flush();

        try {
            $client->request('GET', '/video-playlists');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/video-playlists/'.$playlist->getSlug());
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($createdState) {
                $entityManager->remove($state);
            } else {
                $state->setEnabled($originalEnabled ?? true);
            }
            $entityManager->flush();
            $this->removeFixtures($entityManager, [], [$playlist]);
        }
    }

    public function testVideoManagerCanDiscoverPublicPlaylistsFromPlaylistAdministration(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $playlist = (new VideoPlaylist())
            ->setTitle('Admin playlist '.$suffix)
            ->setSlug('admin-playlist-'.$suffix);
        $user = (new User())
            ->setEmail('video-playlist-admin-'.$suffix.'@example.test')
            ->setDisplayName('Video playlist manager')
            ->setPermissions([CmsPermission::VIDEO]);
        $entityManager->persist($playlist);
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/videos/playlist/'.$playlist->getId().'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/video-playlists"]');
        } finally {
            $entityManager->remove($playlist);
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }

    private function video(
        EntityManagerInterface $entityManager,
        ?VideoPlaylist $playlist,
        string $title,
        string $slug,
        ?\DateTimeImmutable $publishedAt,
    ): Video {
        $video = (new Video())
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription('Description for '.$title)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=example')
            ->setPublishedAt($publishedAt);
        if ($playlist !== null) {
            $video->addPlaylist($playlist);
        }
        $entityManager->persist($video);

        return $video;
    }

    /** @param list<Video> $videos
     * @param list<VideoPlaylist> $playlists
     */
    private function removeFixtures(EntityManagerInterface $entityManager, array $videos, array $playlists): void
    {
        foreach ($videos as $video) {
            $entityManager->remove($video);
        }
        foreach ($playlists as $playlist) {
            $entityManager->remove($playlist);
        }
        $entityManager->flush();
    }
}
