<?php

declare(strict_types=1);

namespace App\Tests\VideoProviderAudit;

use App\Entity\VideoWorkspace\VideoSource;
use App\Service\MediaUrlPolicy;
use App\Service\VideoEmbedResolver;
use App\VideoProviderAudit\SourceAudit;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\StandardProviderAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceAuditTest extends TestCase
{
    #[DataProvider('recognizedSources')]
    public function testRegisteredSourceFormatsProduceOnlyLocalClassification(string $provider, string $url, string $mode): void
    {
        $source = $this->source($provider, $url);
        $result = $this->audit()->classify($source, 'portal.example.test');

        self::assertSame(['status' => 'configured', 'mode' => $mode], $result);
        self::assertStringNotContainsString('private-source-token', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('url', $result);
    }

    public static function recognizedSources(): iterable
    {
        yield 'direct video' => ['mp4', 'https://cdn.example.test/movie.mp4?token=private-source-token', 'video'];
        yield 'HLS' => ['hls', 'https://cdn.example.test/live/index.m3u8', 'hls'];
        yield 'provider iframe' => ['youtube', 'https://www.youtube.com/embed/abcdef12345', 'iframe'];
        yield 'link only' => ['vidmoly', 'https://vidmoly.me/example-video', 'link'];
    }

    public function testUnknownProviderAndUnsupportedLinkFailClosedWithoutReturningSavedUrls(): void
    {
        $audit = $this->audit();
        $unknown = $audit->classify($this->source('missing-provider', 'https://cdn.example.test/movie.mp4?token=private-source-token'), 'portal.example.test');
        $invalid = $audit->classify($this->source('youtube', 'https://youtube.com.attacker.test/embed/abcdef12345'), 'portal.example.test');

        self::assertSame(['status' => 'unknown_provider', 'mode' => null], $unknown);
        self::assertSame(['status' => 'invalid_link', 'mode' => null], $invalid);
        self::assertStringNotContainsString('private-source-token', json_encode($unknown, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('url', $invalid);
    }

    public function testDisabledSourceRetainsItsLocallyRecognizedMode(): void
    {
        $source = $this->source('youtube', 'https://www.youtube.com/embed/abcdef12345', false);

        self::assertSame(
            ['status' => 'disabled', 'mode' => 'iframe'],
            $this->audit()->classify($source, 'portal.example.test'),
        );
    }

    private function audit(): SourceAudit
    {
        $urls = new MediaUrlPolicy();
        $providers = new ProviderRegistry([new StandardProviderAdapter(new VideoEmbedResolver($urls))], $urls);

        return new SourceAudit($providers);
    }

    private function source(string $provider, string $url, bool $enabled = true): VideoSource
    {
        $source = new VideoSource();
        $source->configure('audit test source', $provider, $url, 0, $enabled, true);

        return $source;
    }
}
