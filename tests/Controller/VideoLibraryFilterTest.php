<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoLibraryFilterTest extends WebTestCase
{
    private ?KernelBrowser $fixtureClient = null;

    private ?string $fixtureSuffix = null;

    public function testPublicCategoryAndPlaylistFiltersExposeOnlyEnabledOptionsAndScopedVideos(): void
    {
        $client = static::createClient();
        $this->fixtureClient = $client;
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(4));
        $this->fixtureSuffix = $suffix;

        $category = (new VideoCategory())
            ->setName('Enabled category '.$suffix)
            ->setSlug('enabled-category-'.$suffix);
        $disabledCategory = (new VideoCategory())
            ->setName('Disabled category '.$suffix)
            ->setSlug('disabled-category-'.$suffix)
            ->setEnabled(false);
        $playlist = (new VideoPlaylist())
            ->setTitle('Enabled playlist '.$suffix)
            ->setSlug('enabled-playlist-'.$suffix);
        $disabledPlaylist = (new VideoPlaylist())
            ->setTitle('Disabled playlist '.$suffix)
            ->setSlug('disabled-playlist-'.$suffix)
            ->setEnabled(false);
        foreach ([$category, $disabledCategory, $playlist, $disabledPlaylist] as $filter) {
            $entityManager->persist($filter);
        }
        $entityManager->flush();

        $categoryVideo = $this->createPublishedVideo($client, 'category', $suffix, $category, $playlist);
        $playlistVideo = $this->createPublishedVideo($client, 'playlist', $suffix, $disabledCategory, $playlist);
        $otherVideo = $this->createPublishedVideo($client, 'other', $suffix, $disabledCategory, $disabledPlaylist);
        $entityManager->flush();

        $client->request('GET', '/videos');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main.video-library > .video-filters a[href="/videos?category='.$category->getSlug().'"]');
        self::assertSelectorExists('main.video-library > .video-filters a[href="/videos?playlist='.$playlist->getSlug().'"]');
        self::assertSelectorNotExists('main.video-library > .video-filters a[href*="'.$disabledCategory->getSlug().'"]');
        self::assertSelectorNotExists('main.video-library > .video-filters a[href*="'.$disabledPlaylist->getSlug().'"]');

        $client->request('GET', '/videos?category='.$category->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/videos/'.$categoryVideo->getSlug().'"]');
        self::assertSelectorNotExists('a[href="/videos/'.$playlistVideo->getSlug().'"]');
        self::assertSelectorNotExists('a[href="/videos/'.$otherVideo->getSlug().'"]');

        $client->request('GET', '/videos?playlist='.$playlist->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/videos/'.$categoryVideo->getSlug().'"]');
        self::assertSelectorExists('a[href="/videos/'.$playlistVideo->getSlug().'"]');
        self::assertSelectorNotExists('a[href="/videos/'.$otherVideo->getSlug().'"]');

        $client->request('GET', '/videos?category='.$disabledCategory->getSlug());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/videos?playlist='.$disabledPlaylist->getSlug());
        self::assertResponseStatusCodeSame(404);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureClient !== null && $this->fixtureSuffix !== null) {
                $entityManager = $this->fixtureClient->getContainer()->get(EntityManagerInterface::class);
                foreach ([
                    'video-filter-category-'.$this->fixtureSuffix,
                    'video-filter-playlist-'.$this->fixtureSuffix,
                    'video-filter-other-'.$this->fixtureSuffix,
                ] as $slug) {
                    $video = $entityManager->getRepository(Video::class)->findOneBy(['slug' => $slug]);
                    if ($video instanceof Video) {
                        $entityManager->remove($video);
                    }
                }

                foreach ([
                    VideoCategory::class => ['enabled-category-'.$this->fixtureSuffix, 'disabled-category-'.$this->fixtureSuffix],
                    VideoPlaylist::class => ['enabled-playlist-'.$this->fixtureSuffix, 'disabled-playlist-'.$this->fixtureSuffix],
                ] as $class => $slugs) {
                    foreach ($slugs as $slug) {
                        $filter = $entityManager->getRepository($class)->findOneBy(['slug' => $slug]);
                        if ($filter !== null) {
                            $entityManager->remove($filter);
                        }
                    }
                }
                $entityManager->flush();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function createPublishedVideo(
        KernelBrowser $client,
        string $label,
        string $suffix,
        VideoCategory $category,
        VideoPlaylist $playlist,
    ): Video {
        $video = (new Video())
            ->setTitle('Filter '.$label.' '.$suffix)
            ->setSlug('video-filter-'.$label.'-'.$suffix)
            ->setDescription('Video category and playlist filter regression')
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setCategory($category)
            ->addPlaylist($playlist)
            ->setPublishedAt(new DateTimeImmutable('-1 hour'));
        $this->entityManager($client)->persist($video);

        return $video;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
