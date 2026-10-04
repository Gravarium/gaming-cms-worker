<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicVideoLibraryControllerTest extends WebTestCase
{
    public function testCombinedFiltersPaginatePublishedVideosAndPreserveTheSelection(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $category = (new VideoCategory())->setName('Guides '.$suffix)->setSlug('guides-'.$suffix);
        $playlist = (new VideoPlaylist())->setTitle('Weekly '.$suffix)->setSlug('weekly-'.$suffix);
        $em = $this->em($client);
        $em->persist($category);
        $em->persist($playlist);

        $publishedAt = new \DateTimeImmutable('-2 hours');
        for ($number = 1; $number <= 25; ++$number) {
            $this->video(
                $client,
                $suffix.'-video-'.sprintf('%02d', $number),
                'Guide '.sprintf('%02d', $number),
                $publishedAt,
                category: $category,
                playlist: $playlist,
            );
        }
        $this->video(
            $client,
            $suffix.'-unrelated',
            'Unrelated video',
            $publishedAt,
        );
        $this->video(
            $client,
            $suffix.'-future',
            'Future video',
            new \DateTimeImmutable('+2 days'),
            category: $category,
            playlist: $playlist,
        );
        $this->video(
            $client,
            $suffix.'-draft',
            'Disabled video',
            $publishedAt,
            enabled: false,
            category: $category,
            playlist: $playlist,
        );
        $this->video(
            $client,
            $suffix.'-unpublished',
            'Unpublished video',
            null,
            category: $category,
            playlist: $playlist,
        );
        $em->flush();

        $client->request('GET', '/videos/library?category='.$category->getSlug().'&playlist='.$playlist->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(24, 'article.video-card');
        self::assertSelectorTextContains('article.video-card:first-of-type h2', 'Guide 25');
        self::assertSelectorExists('nav.pagination a[rel="next"][href*="category='.$category->getSlug().'"][href*="playlist='.$playlist->getSlug().'"][href*="page=2"]');
        self::assertStringNotContainsString('Unrelated video', $client->getResponse()->getContent() ?? '');
        self::assertStringNotContainsString('Future video', $client->getResponse()->getContent() ?? '');
        self::assertStringNotContainsString('Disabled video', $client->getResponse()->getContent() ?? '');
        self::assertStringNotContainsString('Unpublished video', $client->getResponse()->getContent() ?? '');

        $client->click($client->getCrawler()->filter('nav.pagination a[rel="next"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'article.video-card');
        self::assertSelectorTextContains('article.video-card h2', 'Guide 01');
        self::assertSelectorExists('nav.pagination a[rel="prev"][href*="category='.$category->getSlug().'"][href*="playlist='.$playlist->getSlug().'"][href*="page=1"]');
    }

    public function testUnknownOrDisabledFiltersAndNonEmptyOutOfRangePageReturnNotFound(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $category = (new VideoCategory())->setName('Category '.$suffix)->setSlug('category-'.$suffix);
        $disabledCategory = (new VideoCategory())->setName('Hidden category '.$suffix)->setSlug('hidden-'.$suffix)->setEnabled(false);
        $disabledPlaylist = (new VideoPlaylist())->setTitle('Hidden playlist '.$suffix)->setSlug('hidden-playlist-'.$suffix)->setEnabled(false);
        $em = $this->em($client);
        $em->persist($category);
        $em->persist($disabledCategory);
        $em->persist($disabledPlaylist);
        $this->video($client, $suffix.'-visible', 'Visible video', new \DateTimeImmutable('-1 hour'), category: $category);
        $em->flush();

        $client->request('GET', '/videos/library?category=missing-'.$suffix);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/videos/library?category='.$disabledCategory->getSlug());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/videos/library?playlist='.$disabledPlaylist->getSlug());
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/videos/library?category='.$category->getSlug().'&page=2');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyCategoryShowsAUsefulEmptyState(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $category = (new VideoCategory())->setName('Empty category '.$suffix)->setSlug('empty-'.$suffix);
        $this->em($client)->persist($category);
        $this->em($client)->flush();

        $client->request('GET', '/videos/library?category='.$category->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.video-grid .panel h2', 'Noch keine Videos');
        self::assertSelectorTextContains('main', 'Für diese Auswahl wurden keine veröffentlichten Videos gefunden.');
    }

    public function testDisabledVideoModuleHidesTheLibrary(): void
    {
        $client = static::createClient();
        $previous = $this->setVideoModuleEnabled($client, false);

        try {
            $client->request('GET', '/videos/library');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreVideoModuleEnabled($client, $previous);
        }
    }

    public function testMalformedPageParametersAreRejectedAndSupportedCapIsEnforced(): void
    {
        $client = static::createClient();
        $previous = $this->setVideoModuleEnabled($client, true);

        try {
            $client->request('GET', '/videos/library');
            self::assertResponseIsSuccessful();

            $client->request('GET', '/videos/library?page=1');
            self::assertResponseIsSuccessful();

            foreach (['page=', 'page=0', 'page=-1', 'page=abc', 'page=1.5', 'page=01', 'page[]=1'] as $query) {
                $client->request('GET', '/videos/library?'.$query);
                self::assertResponseStatusCodeSame(400, 'Expected malformed query to be rejected: '.$query);
            }

            $client->request('GET', '/videos/library?page=10001');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreVideoModuleEnabled($client, $previous);
        }
    }

    private function video(
        KernelBrowser $client,
        string $slug,
        string $title,
        ?\DateTimeImmutable $publishedAt,
        bool $enabled = true,
        ?VideoCategory $category = null,
        ?VideoPlaylist $playlist = null,
    ): Video {
        $video = (new Video())
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription('Description for '.$title)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setCategory($category)
            ->setPublishedAt($publishedAt)
            ->setEnabled($enabled);
        if ($playlist !== null) {
            $video->addPlaylist($playlist);
        }

        $this->em($client)->persist($video);

        return $video;
    }

    private function setVideoModuleEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'video');
        $previous = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('video');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreVideoModuleEnabled(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'video');
        if ($state === null) {
            return;
        }
        if ($previous === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
