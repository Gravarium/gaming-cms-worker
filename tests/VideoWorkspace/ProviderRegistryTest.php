<?php

declare(strict_types=1);

namespace App\Tests\VideoWorkspace;

use App\Service\MediaUrlPolicy;
use App\Service\VideoEmbedResolver;
use App\VideoWorkspace\ProviderAdapter;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\StandardProviderAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    #[DataProvider('sources')]
    public function testDocumentedSourceResolution(string $provider, string $input, string $mode, string $output): void
    {
        self::assertSame(['mode' => $mode, 'url' => $output], $this->registry()->resolve($provider, $input, 'portal.example.test'));
    }
    public static function sources(): iterable
    {
        yield ['youtube', 'https://youtu.be/abcdef12345', 'iframe', 'https://www.youtube-nocookie.com/embed/abcdef12345'];
        yield ['vimeo', 'https://vimeo.com/123456789', 'iframe', 'https://player.vimeo.com/video/123456789'];
        yield ['twitch', 'https://twitch.tv/example', 'iframe', 'https://player.twitch.tv/?channel=example&parent=portal.example.test'];
        yield ['voe', 'https://voe.sx/abc123456789', 'iframe', 'https://voe.sx/e/abc123456789'];
        yield ['doodstream', 'https://dood.to/d/abc123456789', 'iframe', 'https://dood.to/e/abc123456789'];
        yield ['filemoon', 'https://filemoon.org/abc123456789/embed', 'iframe', 'https://filemoon.org/abc123456789/embed'];
        yield ['filemoon', 'https://filemoon.sx/e/abc123456789', 'link', 'https://filemoon.sx/e/abc123456789'];
        yield ['vidmoly', 'https://vidmoly.me/example-video', 'link', 'https://vidmoly.me/example-video'];
        yield ['dailymotion', 'https://www.dailymotion.com/video/x84sh87', 'iframe', 'https://www.dailymotion.com/embed/video/x84sh87'];
        yield ['cloudflare', 'https://customer-example.cloudflarestream.com/123456789abcdef/iframe', 'iframe', 'https://customer-example.cloudflarestream.com/123456789abcdef/iframe'];
        yield ['peertube', 'https://video.example.test/videos/embed/52a10666-3a18-4e73-93da-e8d3c12c305a', 'iframe', 'https://video.example.test/videos/embed/52a10666-3a18-4e73-93da-e8d3c12c305a'];
        yield ['mp4', 'https://cdn.example.test/owned.mp4?token=public-playback-token', 'video', 'https://cdn.example.test/owned.mp4?token=public-playback-token'];
        yield ['webm', 'https://cdn.example.test/owned.webm', 'video', 'https://cdn.example.test/owned.webm'];
        yield ['hls', 'https://cdn.example.test/live/index.m3u8', 'hls', 'https://cdn.example.test/live/index.m3u8'];
    }
    #[DataProvider('unsafeSources')]
    public function testUnknownAndUnsafeSourcesFailClosed(string $provider, string $input): void
    {
        self::assertNull($this->registry()->resolve($provider, $input, 'portal.example.test'));
    }
    public static function unsafeSources(): iterable
    {
        yield ['mp4', 'http://cdn.example.test/video.mp4'];
        yield ['hls', 'https://127.0.0.1/live.m3u8'];
        yield ['hls', 'https://169.254.169.254/live.m3u8'];
        yield ['hls', 'https://user:password@cdn.example.test/live.m3u8'];
        yield ['voe', 'https://voe.sx.attacker.test/e/abc123456789'];
        yield ['voe', 'https://voe.sx:8443/e/abc123456789'];
        yield ['youtube', 'https://youtube.com.attacker.test/watch?v=abcdef12345'];
        yield ['doodstream', 'https://unknown-dood-domain.test/e/abc123456789'];
        yield ['mp4', 'https://cdn.example.test/watch'];
        yield ['hls', 'javascript:alert(1)'];
        yield ['missing', 'https://cdn.example.test/video.mp4'];
        yield ['filemoon', 'https://filemoon.org.attacker.test/abc123/embed'];
        yield ['peertube', 'https://localhost/videos/embed/52a10666-3a18-4e73-93da-e8d3c12c305a'];
    }
    public function testOwnerCanRegisterOwnAuthorizedProviderWithoutCoreChanges(): void
    {
        $adapter = new class implements ProviderAdapter {
            public function catalogue(): array { return ['owner-service' => ['label' => 'Owner service', 'documentation' => 'https://owner.example.test/docs', 'capability' => 'direct']]; }
            public function resolve(string $provider, string $sourceUrl, string $parentHost): ?array { return ['mode' => 'hls', 'url' => $sourceUrl]; }
        };
        $registry = new ProviderRegistry([$adapter], new MediaUrlPolicy());
        self::assertSame('hls', $registry->resolve('owner-service', 'https://cdn.example.test/live.m3u8', 'portal.example.test')['mode']);
        self::assertNull($registry->resolve('owner-service', 'https://127.0.0.1/live.m3u8', 'portal.example.test'));
    }
    public function testDuplicateProviderIdentityIsRejected(): void
    {
        $urls = new MediaUrlPolicy(); $adapter = new StandardProviderAdapter(new VideoEmbedResolver($urls));
        $this->expectException(\LogicException::class); new ProviderRegistry([$adapter, $adapter], $urls);
    }
    private function registry(): ProviderRegistry
    {
        $urls = new MediaUrlPolicy(); return new ProviderRegistry([new StandardProviderAdapter(new VideoEmbedResolver($urls))], $urls);
    }
}
