<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use App\Entity\VideoCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class VideoCategoryPaginationTest extends WebTestCase
{
    public function testCategoryVideosArePagedStablyAndOnlyVisibleVideosAreCounted(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(6));
        $category = (new VideoCategory())
            ->setName('WCP615 Kategorie '.$suffix)
            ->setSlug('wcp615-category-'.$suffix)
            ->setDescription('WCP615 Beschreibung '.$suffix);
        $otherCategory = (new VideoCategory())
            ->setName('WCP615 Andere Kategorie '.$suffix)
            ->setSlug('wcp615-other-'.$suffix);
        $categories = [$category, $otherCategory];
        $videos = [];
        $visible = [];
        $publishedAt = new \DateTimeImmutable('2026-01-15T12:00:00+00:00');

        for ($index = 1; $index <= 25; ++$index) {
            $video = $this->createVideo(
                $category,
                sprintf('WCP615 Video %02d '.$suffix, $index),
                sprintf('wcp615-%s-visible-%02d', $suffix, $index),
                new \DateTimeImmutable('-'.($index % 3 + 1).' days'),
                $index % 4 === 0,
            );
            $visible[] = $video;
            $videos[] = $video;
        }

        $videos[] = $this->createVideo(
            $category,
            'WCP615 Disabled '.$suffix,
            'wcp615-'.$suffix.'-disabled',
            $publishedAt,
            false,
            enabled: false,
        );
        $videos[] = $this->createVideo(
            $category,
            'WCP615 Draft '.$suffix,
            'wcp615-'.$suffix.'-draft',
            null,
            false,
        );
        $videos[] = $this->createVideo(
            $category,
            'WCP615 Future '.$suffix,
            'wcp615-'.$suffix.'-future',
            new \DateTimeImmutable('+1 day'),
            false,
        );
        $videos[] = $this->createVideo(
            $otherCategory,
            'WCP615 Foreign '.$suffix,
            'wcp615-'.$suffix.'-foreign',
            $publishedAt,
            false,
        );

        foreach ($categories as $fixture) {
            $entityManager->persist($fixture);
        }
        foreach ($videos as $video) {
            $entityManager->persist($video);
        }
        $entityManager->flush();

        try {
            $ordered = [];
            foreach ($visible as $video) {
                $id = $video->getId();
                self::assertNotNull($id);
                $ordered[] = [
                    'id' => (string) $id,
                    'featured' => $video->isFeatured(),
                    'publishedAt' => $video->getPublishedAt()?->getTimestamp() ?? 0,
                ];
            }
            usort($ordered, static function (array $left, array $right): int {
                $featuredComparison = ($right['featured'] ? 1 : 0) <=> ($left['featured'] ? 1 : 0);
                if ($featuredComparison !== 0) {
                    return $featuredComparison;
                }

                $publishedAtComparison = $right['publishedAt'] <=> $left['publishedAt'];

                return $publishedAtComparison !== 0 ? $publishedAtComparison : ((int) $right['id'] <=> (int) $left['id']);
            });
            $expectedIds = array_column($ordered, 'id');
            $categoryUrl = '/videos/categories/'.$category->getSlug();

            $client->request('GET', $categoryUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(20, '.video-category-entry');
            self::assertSelectorTextContains('.video-category-count', '25 veröffentlichte Videos');
            self::assertSelectorTextContains('.video-category-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('.video-category-pagination a[rel="next"]');
            $firstPageIds = $client->getCrawler()->filter('.video-category-entry')->each(
                static fn (Crawler $node): string => (string) $node->attr('data-video-id'),
            );
            self::assertSame(array_slice($expectedIds, 0, 20), $firstPageIds);

            $nextUrl = (string) $client->getCrawler()->filter('.video-category-pagination a[rel="next"]')->attr('href');
            self::assertStringContainsString($category->getSlug(), $nextUrl);
            self::assertStringContainsString('page=2', $nextUrl);

            $client->request('GET', $categoryUrl, ['page' => '2']);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(5, '.video-category-entry');
            self::assertSelectorTextContains('.video-category-pagination', 'Seite 2 von 2');
            self::assertSelectorExists('.video-category-pagination a[rel="prev"]');
            $secondPageIds = $client->getCrawler()->filter('.video-category-entry')->each(
                static fn (Crawler $node): string => (string) $node->attr('data-video-id'),
            );
            self::assertSame(array_slice($expectedIds, 20), $secondPageIds);

            $client->request('GET', $categoryUrl, ['page' => '3']);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures($client, $videos, $categories);
        }
    }

    public function testPageInputAndUnavailableCategoriesFailClosed(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(6));
        $category = (new VideoCategory())
            ->setName('WCP615 Sichtbar '.$suffix)
            ->setSlug('wcp615-visible-'.$suffix);
        $disabledCategory = (new VideoCategory())
            ->setName('WCP615 Deaktiviert '.$suffix)
            ->setSlug('wcp615-disabled-'.$suffix)
            ->setEnabled(false);
        $categories = [$category, $disabledCategory];
        $videos = [
            $this->createVideo(
                $category,
                'WCP615 Ein Video '.$suffix,
                'wcp615-'.$suffix.'-only',
                new \DateTimeImmutable('-1 hour'),
                false,
            ),
        ];

        foreach ($categories as $fixture) {
            $entityManager->persist($fixture);
        }
        foreach ($videos as $video) {
            $entityManager->persist($video);
        }
        $entityManager->flush();

        try {
            $categoryUrl = '/videos/categories/'.$category->getSlug();
            foreach ([
                $categoryUrl.'?page=abc',
                $categoryUrl.'?page[]=2',
                $categoryUrl.'?page=1001',
            ] as $invalidUrl) {
                $client->request('GET', $invalidUrl);
                self::assertResponseStatusCodeSame(400);
            }

            $client->request('GET', $categoryUrl, ['page' => '2']);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/categories/'.$disabledCategory->getSlug());
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/categories/missing-'.$suffix);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures($client, $videos, $categories);
        }
    }

    /**
     * @param list<Video> $videos
     * @param list<VideoCategory> $categories
     */
    private function removeFixtures(KernelBrowser $client, array $videos, array $categories): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($videos as $video) {
            $id = $video->getId();
            if ($id === null) {
                continue;
            }

            $stored = $entityManager->find(Video::class, $id);
            if ($stored instanceof Video) {
                $entityManager->remove($stored);
            }
        }
        $entityManager->flush();

        foreach ($categories as $category) {
            $id = $category->getId();
            if ($id === null) {
                continue;
            }

            $stored = $entityManager->find(VideoCategory::class, $id);
            if ($stored instanceof VideoCategory) {
                $entityManager->remove($stored);
            }
        }
        $entityManager->flush();
    }

    private function createVideo(
        VideoCategory $category,
        string $title,
        string $slug,
        ?\DateTimeImmutable $publishedAt,
        bool $featured,
        bool $enabled = true,
    ): Video {
        return (new Video())
            ->setCategory($category)
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription('Beschreibung '.$title)
            ->setSourceType(Video::SOURCE_EXTERNAL)
            ->setSourceUrl('https://videos.example.test/'.$slug)
            ->setPublishedAt($publishedAt)
            ->setFeatured($featured)
            ->setEnabled($enabled);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
