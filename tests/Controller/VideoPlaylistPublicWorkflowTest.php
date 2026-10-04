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
        $playlistSlug = 'public-playlist-'.$suffix;
        $hiddenPlaylistSlug = 'hidden-playlist-'.$suffix;
        $videoSlugs = [
            'visible-video-'.$suffix,
            'draft-video-'.$suffix,
            'disabled-video-'.$suffix,
            'future-video-'.$suffix,
            'unrelated-video-'.$suffix,
        ];
        $playlistSlugs = [$playlistSlug, $hiddenPlaylistSlug];

        try {
            $playlist = (new VideoPlaylist())
                ->setTitle('Public playlist '.$suffix)
                ->setSlug($playlistSlug)
                ->setDescription('A public playlist '.$suffix);
            $hiddenPlaylist = (new VideoPlaylist())
                ->setTitle('Hidden playlist '.$suffix)
                ->setSlug($hiddenPlaylistSlug)
                ->setEnabled(false);
            $entityManager->persist($playlist);
            $entityManager->persist($hiddenPlaylist);

            $visibleVideo = $this->video($entityManager, $playlist, 'Visible video '.$suffix, $videoSlugs[0], new \DateTimeImmutable('-1 hour'));
            $draftVideo = $this->video($entityManager, $playlist, 'Draft video '.$suffix, $videoSlugs[1], null);
            $disabledVideo = $this->video($entityManager, $playlist, 'Disabled video '.$suffix, $videoSlugs[2], new \DateTimeImmutable('-1 hour'));
            $disabledVideo->setEnabled(false);
            $futureVideo = $this->video($entityManager, $playlist, 'Future video '.$suffix, $videoSlugs[3], new \DateTimeImmutable('+1 day'));
            $unrelatedVideo = $this->video($entityManager, null, 'Unrelated video '.$suffix, $videoSlugs[4], new \DateTimeImmutable('-1 hour'));
            $entityManager->flush();

            $client->request('GET', '/video-playlists');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Video-Playlists');
            $directory = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($playlist->getTitle(), $directory);
            self::assertStringContainsString('/video-playlists/'.$playlistSlug, $directory);
            self::assertStringNotContainsString($hiddenPlaylist->getTitle(), $directory);

            $client->request('GET', '/video-playlists/'.$playlistSlug);
            self::assertResponseIsSuccessful();
            $page = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($playlist->getTitle(), $page);
            self::assertStringContainsString($visibleVideo->getTitle(), $page);
            self::assertStringNotContainsString($draftVideo->getTitle(), $page);
            self::assertStringNotContainsString($disabledVideo->getTitle(), $page);
            self::assertStringNotContainsString($futureVideo->getTitle(), $page);
            self::assertStringNotContainsString($unrelatedVideo->getTitle(), $page);

            $client->request('GET', '/video-playlists/'.$hiddenPlaylistSlug);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures(
                $client->getContainer()->get(EntityManagerInterface::class),
                $videoSlugs,
                $playlistSlugs,
            );
        }
    }

    public function testPublicPlaylistEscapesStoredHtml(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $slug = 'escaping-playlist-'.$suffix;
        $titlePayload = '<script>alert("playlist-'.$suffix.'")</script>';
        $descriptionPayload = 'A description <img src=x onerror="alert(1)"> end';
        $playlist = (new VideoPlaylist())
            ->setTitle($titlePayload)
            ->setSlug($slug)
            ->setDescription($descriptionPayload);
        $entityManager->persist($playlist);
        $entityManager->flush();

        try {
            $crawler = $client->request('GET', '/video-playlists/'.$slug);

            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($titlePayload, $content);
            self::assertStringNotContainsString($descriptionPayload, $content);
            self::assertStringContainsString(htmlspecialchars($titlePayload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);
            self::assertStringContainsString(htmlspecialchars($descriptionPayload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);

            $main = $crawler->filter('main.video-library');
            self::assertSame(0, $main->filter('script, img[onerror]')->count());
            self::assertSame($titlePayload, $crawler->filter('h1')->text());
            self::assertSame($descriptionPayload, $crawler->filter('.article-body')->text('', true));
        } finally {
            $this->removeFixtures(
                $client->getContainer()->get(EntityManagerInterface::class),
                [],
                [$slug],
            );
        }
    }

    public function testVideoModuleDeactivationHidesPlaylistDirectoryAndDetails(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $playlistSlug = 'module-gated-playlist-'.$suffix;
        $playlist = (new VideoPlaylist())
            ->setTitle('Module-gated playlist '.$suffix)
            ->setSlug($playlistSlug);
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

            $client->request('GET', '/video-playlists/'.$playlistSlug);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanupState = $cleanupManager->find(CmsModuleState::class, 'video');
            if ($createdState) {
                if ($cleanupState instanceof CmsModuleState) {
                    $cleanupManager->remove($cleanupState);
                }
            } elseif ($cleanupState instanceof CmsModuleState) {
                $cleanupState->setEnabled((bool) $originalEnabled);
            }

            $cleanupPlaylist = $cleanupManager->getRepository(VideoPlaylist::class)->findOneBy(['slug' => $playlistSlug]);
            if ($cleanupPlaylist instanceof VideoPlaylist) {
                $cleanupManager->remove($cleanupPlaylist);
            }
            $cleanupManager->flush();
        }
    }

    public function testVideoManagerCanDiscoverPublicPlaylistsFromPlaylistAdministration(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $playlistSlug = 'admin-playlist-'.$suffix;
        $userEmail = 'video-playlist-admin-'.$suffix.'@example.test';
        $playlist = (new VideoPlaylist())
            ->setTitle('Admin playlist '.$suffix)
            ->setSlug($playlistSlug);
        $user = (new User())
            ->setEmail($userEmail)
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
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanupPlaylist = $cleanupManager->getRepository(VideoPlaylist::class)->findOneBy(['slug' => $playlistSlug]);
            $cleanupUser = $cleanupManager->getRepository(User::class)->findOneBy(['email' => $userEmail]);
            if ($cleanupPlaylist instanceof VideoPlaylist) {
                $cleanupManager->remove($cleanupPlaylist);
            }
            if ($cleanupUser instanceof User) {
                $cleanupManager->remove($cleanupUser);
            }
            $cleanupManager->flush();
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

    /** @param list<string> $videoSlugs
     * @param list<string> $playlistSlugs
     */
    private function removeFixtures(EntityManagerInterface $entityManager, array $videoSlugs, array $playlistSlugs): void
    {
        foreach ($videoSlugs as $slug) {
            $video = $entityManager->getRepository(Video::class)->findOneBy(['slug' => $slug]);
            if ($video instanceof Video) {
                $entityManager->remove($video);
            }
        }
        foreach ($playlistSlugs as $slug) {
            $playlist = $entityManager->getRepository(VideoPlaylist::class)->findOneBy(['slug' => $slug]);
            if ($playlist instanceof VideoPlaylist) {
                $entityManager->remove($playlist);
            }
        }
        $entityManager->flush();
    }
}
