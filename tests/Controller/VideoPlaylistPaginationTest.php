<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use App\Entity\VideoPlaylist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoPlaylistPaginationTest extends WebTestCase
{
    public function testPlaylistPaginatesOnlyPublishedVideosAndKeepsStableOrderAndSlug(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $playlistSlug = 'paginated-playlist-'.$suffix;
        $playlist = (new VideoPlaylist())
            ->setTitle('Paginated playlist '.$suffix)
            ->setSlug($playlistSlug);
        $videoSlugs = [];
        $playlistSlugs = [$playlistSlug];

        try {
            $em->persist($playlist);
            $publishedAt = new \DateTimeImmutable('-1 hour');
            for ($number = 1; $number <= 25; ++$number) {
                $slug = sprintf('%s-video-%02d', $suffix, $number);
                $videoSlugs[] = $slug;
                $this->video($em, $playlist, 'Playlist video '.sprintf('%02d', $number), $slug, $publishedAt);
            }

            $draftSlug = $suffix.'-draft';
            $disabledSlug = $suffix.'-disabled';
            $futureSlug = $suffix.'-future';
            $videoSlugs = [...$videoSlugs, $draftSlug, $disabledSlug, $futureSlug];
            $this->video($em, $playlist, 'Unpublished draft '.$suffix, $draftSlug, null);
            $this->video($em, $playlist, 'Disabled video '.$suffix, $disabledSlug, $publishedAt, false);
            $this->video($em, $playlist, 'Future video '.$suffix, $futureSlug, new \DateTimeImmutable('+1 day'));
            $em->flush();

            $client->request('GET', '/video-playlists/'.$playlistSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(24, 'article.video-card');
            self::assertSelectorTextContains('article.video-card:first-of-type h2', 'Playlist video 25');
            self::assertSelectorTextContains('.muted', '25 veröffentlichte Videos');
            self::assertSelectorExists('nav.pagination[aria-label="Seitennavigation"] a[rel="next"][href*="/video-playlists/'.$playlistSlug.'"][href*="page=2"]');
            $firstPage = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Unpublished draft '.$suffix, $firstPage);
            self::assertStringNotContainsString('Disabled video '.$suffix, $firstPage);
            self::assertStringNotContainsString('Future video '.$suffix, $firstPage);

            $client->click($client->getCrawler()->filter('nav.pagination a[rel="next"]')->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, 'article.video-card');
            self::assertSelectorTextContains('article.video-card h2', 'Playlist video 01');
            self::assertSelectorTextContains('.muted', 'Seite 2 von 2');
            self::assertSelectorExists('nav.pagination a[rel="prev"][href*="/video-playlists/'.$playlistSlug.'"][href*="page=1"]');
        } finally {
            $this->removeFixtures($client, $videoSlugs, $playlistSlugs);
        }
    }

    public function testEmptyPlaylistUsesPageOneAndNonEmptyOutOfRangePagesReturnNotFound(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $emptySlug = 'empty-playlist-'.$suffix;
        $filledSlug = 'one-video-playlist-'.$suffix;
        $empty = (new VideoPlaylist())->setTitle('Empty playlist '.$suffix)->setSlug($emptySlug);
        $filled = (new VideoPlaylist())->setTitle('One video playlist '.$suffix)->setSlug($filledSlug);
        $videoSlug = $suffix.'-only-video';
        $playlistSlugs = [$emptySlug, $filledSlug];

        try {
            $em->persist($empty);
            $em->persist($filled);
            $this->video($em, $filled, 'Single public video '.$suffix, $videoSlug, new \DateTimeImmutable('-1 hour'));
            $em->flush();

            $client->request('GET', '/video-playlists/'.$emptySlug.'?page=50000');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h2', 'Noch keine veröffentlichten Videos');
            self::assertSelectorTextContains('.muted', '0 veröffentlichte Videos');
            self::assertSelectorNotExists('nav.pagination');

            $client->request('GET', '/video-playlists/'.$filledSlug.'?page=not-a-number');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, 'article.video-card');
            self::assertSelectorTextContains('article.video-card h2', 'Single public video '.$suffix);

            $client->request('GET', '/video-playlists/'.$filledSlug.'?page=2');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures($client, [$videoSlug], $playlistSlugs);
        }
    }

    private function video(
        EntityManagerInterface $em,
        VideoPlaylist $playlist,
        string $title,
        string $slug,
        ?\DateTimeImmutable $publishedAt,
        bool $enabled = true,
    ): void {
        $video = (new Video())
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription('Description for '.$title)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setPublishedAt($publishedAt)
            ->setEnabled($enabled)
            ->addPlaylist($playlist);
        $em->persist($video);
    }

    /** @param list<string> $videoSlugs
     * @param list<string> $playlistSlugs
     */
    private function removeFixtures(KernelBrowser $client, array $videoSlugs, array $playlistSlugs): void
    {
        $em = $this->em($client);
        foreach ($videoSlugs as $slug) {
            $video = $em->getRepository(Video::class)->findOneBy(['slug' => $slug]);
            if ($video instanceof Video) {
                $em->remove($video);
            }
        }
        foreach ($playlistSlugs as $slug) {
            $playlist = $em->getRepository(VideoPlaylist::class)->findOneBy(['slug' => $slug]);
            if ($playlist instanceof VideoPlaylist) {
                $em->remove($playlist);
            }
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
