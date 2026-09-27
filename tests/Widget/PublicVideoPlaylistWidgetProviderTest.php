<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Video;
use App\Entity\VideoPlaylist;
use App\Layout\LayoutValidator;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicVideoPlaylistWidgetProviderTest extends WebTestCase
{
    /** @var list<VideoPlaylist> */
    private array $createdPlaylists = [];

    /** @var list<Video> */
    private array $createdVideos = [];

    private ?EntityManagerInterface $entityManager = null;

    protected function tearDown(): void
    {
        try {
            if ($this->entityManager !== null) {
                foreach ($this->createdVideos as $video) {
                    $this->entityManager->remove($video);
                }
                foreach ($this->createdPlaylists as $playlist) {
                    $this->entityManager->remove($playlist);
                }
                $this->entityManager->flush();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testProviderIsDiscoverableAndFiltersPlaylistsToPublicVideos(): void
    {
        $client = static::createClient();
        $this->playlist($client, 'Beta playlist', 'beta');
        $this->playlist($client, 'Alpha playlist', 'alpha');
        $this->playlist($client, 'Gamma playlist', 'gamma');
        $this->playlist($client, 'Disabled playlist', 'disabled-playlist', enabled: false);
        $this->playlist($client, 'Empty playlist', 'empty-playlist', withVideo: false);
        $this->playlist($client, 'Disabled video playlist', 'disabled-video', videoEnabled: false);
        $this->playlist($client, 'Unpublished video playlist', 'unpublished-video', published: false);
        $this->playlist(
            $client,
            'Future video playlist',
            'future-video',
            publishedAt: new \DateTimeImmutable('+2 days'),
        );

        $registry = $client->getContainer()->get(WidgetRegistry::class);
        $definition = $registry->get('video.public-playlists');
        self::assertInstanceOf(WidgetDefinition::class, $definition);
        self::assertSame('video', $definition->module);
        self::assertSame('widget/public_video_playlists.html.twig', $definition->template);
        $widgetSettings = $client->getContainer()->get(LayoutValidator::class)->widgetSchema('video.public-playlists');
        self::assertSame(1, $widgetSettings['count']['min']);
        self::assertSame(12, $widgetSettings['count']['max']);

        $availableKeys = array_map(
            static fn (WidgetDefinition $widget): string => $widget->key,
            $registry->availableDefinitions(),
        );
        self::assertContains('video.public-playlists', $availableKeys);

        $data = $registry->data('video.public-playlists', ['count' => 2]);
        $items = $this->playlistsFrom($data);
        self::assertCount(2, $items);
        self::assertSame(['Alpha playlist', 'Beta playlist'], array_map(
            static fn (VideoPlaylist $playlist): string => $playlist->getTitle(),
            $items,
        ));

        $allPublic = $this->playlistsFrom($registry->data('video.public-playlists', ['count' => 12]));
        self::assertCount(3, $allPublic);
    }

    public function testProviderCapsConfiguredCountAtTwelveAndOrdersDeterministically(): void
    {
        $client = static::createClient();
        for ($number = 14; $number >= 1; --$number) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $this->playlist($client, 'Playlist '.$suffix, 'playlist-'.$suffix);
        }

        $registry = $client->getContainer()->get(WidgetRegistry::class);
        $items = $this->playlistsFrom($registry->data('video.public-playlists', ['count' => 99]));

        self::assertCount(12, $items);
        self::assertSame(
            array_map(static fn (int $number): string => 'Playlist '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), range(1, 12)),
            array_map(static fn (VideoPlaylist $playlist): string => $playlist->getTitle(), $items),
        );
    }

    public function testTemplateLinksToPlaylistFilterEscapesTextAndShowsEmptyState(): void
    {
        $client = static::createClient();
        $playlist = $this->playlist(
            $client,
            '<script>alert(1)</script>',
            'safe-playlist-slug',
            description: '<img src=x onerror=alert(1)>',
        );
        $registry = $client->getContainer()->get(WidgetRegistry::class);
        $twig = $client->getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('widget/public_video_playlists.html.twig', [
            'data' => $registry->data('video.public-playlists', ['count' => 6]),
        ]);

        self::assertStringContainsString('href="/videos?playlist='.$playlist->getSlug().'"', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);

        $empty = $twig->render('widget/public_video_playlists.html.twig', ['data' => ['items' => []]]);
        self::assertStringContainsString('Derzeit sind keine öffentlichen Video-Playlists verfügbar.', $empty);
    }

    public function testDisabledVideoModuleHidesWidgetAndSuppressesItsData(): void
    {
        $client = static::createClient();
        $this->playlist($client, 'Public playlist', 'module-gate-playlist');
        $entityManager = $this->em($client);
        $registry = $client->getContainer()->get(WidgetRegistry::class);
        $videoState = $entityManager->find(CmsModuleState::class, 'video');
        $videoWasEnabled = $videoState?->isEnabled();
        $mediaState = $entityManager->find(CmsModuleState::class, 'media');
        $mediaWasEnabled = $mediaState?->isEnabled();
        $videoState?->setEnabled(true);
        $mediaState?->setEnabled(true);
        $entityManager->flush();

        $disabledVideoState = $videoState;
        try {
            self::assertTrue($registry->available('video.public-playlists'));

            $disabledVideoState ??= (new CmsModuleState())
                ->setModuleKey('video')
                ->updateVersion('1.0.0');
            $disabledVideoState->setEnabled(false);
            $entityManager->persist($disabledVideoState);
            $entityManager->flush();

            self::assertFalse($registry->available('video.public-playlists'));
            $availableKeys = array_map(
                static fn (WidgetDefinition $widget): string => $widget->key,
                $registry->availableDefinitions(),
            );
            self::assertNotContains('video.public-playlists', $availableKeys);
            self::assertSame([], $registry->data('video.public-playlists', ['count' => 6]));
        } finally {
            if ($videoState === null && $disabledVideoState !== null) {
                $entityManager->remove($disabledVideoState);
            } elseif ($videoState !== null) {
                $videoState->setEnabled($videoWasEnabled ?? true);
            }
            if ($mediaState !== null) {
                $mediaState->setEnabled($mediaWasEnabled ?? true);
            }
            $entityManager->flush();
        }
    }

    private function playlist(
        KernelBrowser $client,
        string $title,
        string $slug,
        string $description = 'Playlist description',
        bool $enabled = true,
        bool $withVideo = true,
        bool $videoEnabled = true,
        bool $published = true,
        ?\DateTimeImmutable $publishedAt = null,
    ): VideoPlaylist {
        $uniqueSlug = $slug.'-'.bin2hex(random_bytes(4));
        $playlist = (new VideoPlaylist())
            ->setTitle($title)
            ->setSlug($uniqueSlug)
            ->setDescription($description)
            ->setEnabled($enabled);
        $entityManager = $this->em($client);
        $entityManager->persist($playlist);
        $this->createdPlaylists[] = $playlist;

        if ($withVideo) {
            $video = (new Video())
                ->setTitle('Video for '.$slug)
                ->setSlug('video-'.$uniqueSlug)
                ->setDescription('Video description')
                ->setEnabled($videoEnabled)
                ->setPublishedAt($published ? ($publishedAt ?? new \DateTimeImmutable('-1 day')) : null)
                ->addPlaylist($playlist);
            $entityManager->persist($video);
            $this->createdVideos[] = $video;
        }

        $entityManager->flush();

        return $playlist;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<VideoPlaylist>
     */
    private function playlistsFrom(array $data): array
    {
        $items = $data['items'] ?? [];
        self::assertIsArray($items);

        $playlists = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => $item instanceof VideoPlaylist,
        ));
        self::assertCount(count($items), $playlists);

        return $playlists;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $this->entityManager = $client->getContainer()->get(EntityManagerInterface::class);
    }
}
