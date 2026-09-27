<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Video;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicVideoSearchControllerTest extends WebTestCase
{
    public function testSearchMatchesTitlesAndDescriptionsButOnlyShowsPublishedEnabledVideos(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableVideoModule($client);
        $publishedAt = new \DateTimeImmutable('-1 day');
        $videoIds = [];
        try {
            $videoIds = $this->createVideos($client, [
                [
                    'title' => 'Guide <script>alert(1)</script>',
                    'description' => 'A published title match.',
                    'publishedAt' => $publishedAt,
                    'enabled' => true,
                ],
                [
                    'title' => 'Community episode',
                    'description' => 'A published description guide.',
                    'publishedAt' => $publishedAt,
                    'enabled' => true,
                ],
                [
                    'title' => 'Unpublished guide',
                    'description' => 'Not visible yet.',
                    'publishedAt' => null,
                    'enabled' => true,
                ],
                [
                    'title' => 'Future guide',
                    'description' => 'Scheduled for later.',
                    'publishedAt' => new \DateTimeImmutable('+1 day'),
                    'enabled' => true,
                ],
                [
                    'title' => 'Disabled guide',
                    'description' => 'Hidden from the public catalogue.',
                    'publishedAt' => $publishedAt,
                    'enabled' => false,
                ],
            ]);

            $client->request('GET', '/video-search?q=GUIDE');

            self::assertResponseIsSuccessful();
            self::assertSelectorCount(2, '.video-card');
            self::assertSelectorTextContains('.video-search-count', '2 Treffer');
            self::assertSelectorTextContains('.video-card h2', 'Community episode');
            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('<script>alert(1)</script>', $content);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);

            $visibleIds = $client->getCrawler()->filter('.video-card')->each(
                static fn (Crawler $node): string => (string) $node->attr('data-video-id'),
            );
            self::assertSame(
                array_map('strval', array_reverse(array_slice($videoIds, 0, 2))),
                $visibleIds,
            );
        } finally {
            $this->removeVideoFixtures($client, $videoIds);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testSearchUsesStableTwentyResultPages(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableVideoModule($client);
        $publishedAt = new \DateTimeImmutable('-2 days');
        $definitions = [];
        for ($index = 1; $index <= 21; ++$index) {
            $definitions[] = [
                'title' => sprintf('Pagination result %02d', $index),
                'description' => 'A stable pagination fixture.',
                'publishedAt' => $publishedAt,
                'enabled' => true,
            ];
        }

        $videoIds = [];
        try {
            $videoIds = $this->createVideos($client, $definitions);
            $client->request('GET', '/video-search?q=pagination');

            self::assertResponseIsSuccessful();
            self::assertSelectorCount(20, '.video-card');
            self::assertSelectorTextContains('.video-search-count', '21 Treffer');
            self::assertSelectorTextContains('.video-search-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('.video-search-pagination a[rel="next"]');

            $firstPageIds = $client->getCrawler()->filter('.video-card')->each(
                static fn ($node): string => (string) $node->attr('data-video-id'),
            );
            $expectedFirstPage = array_map('strval', array_slice(array_reverse($videoIds), 0, 20));
            self::assertSame($expectedFirstPage, $firstPageIds);

            $client->request('GET', '/video-search?q=pagination&page=2');

            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.video-card');
            self::assertSelectorTextContains('.video-search-pagination', 'Seite 2 von 2');
            self::assertSame(
                [(string) $videoIds[0]],
                $client->getCrawler()->filter('.video-card')->each(
                    static fn ($node): string => (string) $node->attr('data-video-id'),
                ),
            );
        } finally {
            $this->removeVideoFixtures($client, $videoIds);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testNoResultsRemainEscapedAndInvalidSearchInputsAreRejected(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableVideoModule($client);
        try {
            $marker = '<script>search-'.bin2hex(random_bytes(8)).'</script>';
            $client->request('GET', '/video-search?q='.rawurlencode($marker));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.video-search-empty', 'Keine passenden veröffentlichten Videos');
            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($marker, $content);
            self::assertStringContainsString(htmlspecialchars($marker, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);

            $invalidUrls = [
                '/video-search?q%5B%5D=guide',
                '/video-search?q=G',
                '/video-search?q='.rawurlencode(str_repeat('x', 101)),
                '/video-search?q=guide%0Asecret',
                '/video-search?q=%FF',
                '/video-search?q=guide&page=abc',
                '/video-search?q=guide&page=1001',
                '/video-search?q=guide&page%5B%5D=2',
                '/video-search?page=2',
            ];
            foreach ($invalidUrls as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(400, 'Expected malformed search input to be rejected: '.$url);
            }
        } finally {
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testDisabledVideoModuleHidesSearchRoute(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableVideoModule($client);
        try {
            $client->getContainer()->get(CmsModuleManager::class)->setEnabled('video', false);
            $client->request('GET', '/video-search?q=guide');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{
     *     mediaExists: bool,
     *     mediaEnabled: bool,
     *     videoExists: bool,
     *     videoEnabled: bool
     * }
     */
    private function enableVideoModule(KernelBrowser $client): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $mediaState = $entityManager->find(CmsModuleState::class, 'media');
        $videoState = $entityManager->find(CmsModuleState::class, 'video');
        $snapshot = [
            'mediaExists' => $mediaState !== null,
            'mediaEnabled' => $mediaState?->isEnabled() ?? true,
            'videoExists' => $videoState !== null,
            'videoEnabled' => $videoState?->isEnabled() ?? true,
        ];

        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('media') || !$modules->isInstalled('video')) {
            self::markTestSkipped('The Video and Media modules must be installed for the public search workflow test.');
        }
        if (!$modules->isEnabled('media')) {
            $modules->setEnabled('media', true);
        }
        if (!$modules->isEnabled('video')) {
            $modules->setEnabled('video', true);
        }

        return $snapshot;
    }

    /**
     * @param list<array{title: string, description: string, publishedAt: ?\DateTimeImmutable, enabled: bool}> $definitions
     * @return list<int>
     */
    private function createVideos(KernelBrowser $client, array $definitions): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $videos = [];
        foreach ($definitions as $definition) {
            $video = (new Video())
                ->setTitle($definition['title'])
                ->setSlug('video-search-'.bin2hex(random_bytes(12)))
                ->setDescription($definition['description'])
                ->setPublishedAt($definition['publishedAt'])
                ->setEnabled($definition['enabled']);
            $entityManager->persist($video);
            $videos[] = $video;
        }
        $entityManager->flush();

        $ids = [];
        foreach ($videos as $video) {
            $id = $video->getId();
            if ($id === null) {
                throw new \LogicException('Persisted video fixture has no database ID.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** @param list<int> $ids */
    private function removeVideoFixtures(KernelBrowser $client, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        foreach ($ids as $id) {
            $video = $entityManager->find(Video::class, $id);
            if ($video !== null) {
                $entityManager->remove($video);
            }
        }
        $entityManager->flush();
    }

    /**
     * @param array{
     *     mediaExists: bool,
     *     mediaEnabled: bool,
     *     videoExists: bool,
     *     videoEnabled: bool
     * } $snapshot
     */
    private function restoreModuleStates(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        foreach ([
            'media' => ['exists' => $snapshot['mediaExists'], 'enabled' => $snapshot['mediaEnabled']],
            'video' => ['exists' => $snapshot['videoExists'], 'enabled' => $snapshot['videoEnabled']],
        ] as $key => $original) {
            $state = $entityManager->find(CmsModuleState::class, $key);
            if (!$original['exists']) {
                if ($state !== null) {
                    $entityManager->remove($state);
                }
                continue;
            }

            if ($state !== null && $state->isEnabled() !== $original['enabled']) {
                $state->setEnabled($original['enabled']);
            }
        }

        $entityManager->flush();
    }
}
