<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MediaAsset;
use App\Entity\Video;
use App\Service\MediaUrlPolicy;
use App\Service\VideoEmbedResolver;
use PHPUnit\Framework\TestCase;

final class VideoEmbedResolverTest extends TestCase
{
    public function testYoutubeWatchUrlUsesPrivacyEnhancedPlayer(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk');

        self::assertSame(
            ['mode' => 'iframe', 'url' => 'https://www.youtube-nocookie.com/embed/abcdefghijk'],
            $this->resolver()->resolve($video, 'example.test'),
        );
    }

    public function testVimeoUrlIsConvertedToPlayerUrl(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_VIMEO)
            ->setSourceUrl('https://vimeo.com/123456789');

        self::assertSame(
            ['mode' => 'iframe', 'url' => 'https://player.vimeo.com/video/123456789'],
            $this->resolver()->resolve($video, 'example.test'),
        );
    }

    public function testTwitchVideoIncludesCurrentParentHost(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_TWITCH)
            ->setSourceUrl('https://www.twitch.tv/videos/987654321');

        self::assertSame(
            ['mode' => 'iframe', 'url' => 'https://player.twitch.tv/?video=v987654321&parent=gaming.example.test'],
            $this->resolver()->resolve($video, 'gaming.example.test'),
        );
    }

    public function testProviderHostSpoofingIsRejected(): void
    {
        $resolver = $this->resolver();

        $youtube = (new Video())->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://evilyoutube.com/watch?v=abcdefghijk');
        $vimeo = (new Video())->setSourceType(Video::SOURCE_VIMEO)->setSourceUrl('https://example.test/123456789');
        $twitch = (new Video())->setSourceType(Video::SOURCE_TWITCH)->setSourceUrl('https://eviltwitch.tv/videos/987654321');

        self::assertNull($resolver->resolve($youtube, 'example.test'));
        self::assertNull($resolver->resolve($vimeo, 'example.test'));
        self::assertNull($resolver->resolve($twitch, 'example.test'));
    }

    public function testProviderSubdomainsAreRejected(): void
    {
        $resolver = $this->resolver();

        $youtube = (new Video())->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://evil.youtube.com/watch?v=abcdefghijk');
        $vimeo = (new Video())->setSourceType(Video::SOURCE_VIMEO)->setSourceUrl('https://evil.vimeo.com/123456789');
        $twitch = (new Video())->setSourceType(Video::SOURCE_TWITCH)->setSourceUrl('https://evil.twitch.tv/videos/987654321');
        $clip = (new Video())->setSourceType(Video::SOURCE_TWITCH)->setSourceUrl('https://evil.clips.twitch.tv/clip-token');

        self::assertNull($resolver->resolve($youtube, 'example.test'));
        self::assertNull($resolver->resolve($vimeo, 'example.test'));
        self::assertNull($resolver->resolve($twitch, 'example.test'));
        self::assertNull($resolver->resolve($clip, 'example.test'));
    }

    public function testMalformedTwitchParentHostIsRejected(): void
    {
        $resolver = $this->resolver();

        foreach (['-invalid.example', 'example..test', 'example.test:0', 'example.test:65536', 'https://example.test'] as $parentHost) {
            $video = (new Video())
                ->setSourceType(Video::SOURCE_TWITCH)
                ->setSourceUrl('https://www.twitch.tv/videos/987654321');

            self::assertNull($resolver->resolve($video, $parentHost), $parentHost);
        }
    }

    public function testExternalVideoRejectsActiveOrLocalUrls(): void
    {
        $resolver = $this->resolver();

        $javascript = (new Video())->setSourceType(Video::SOURCE_EXTERNAL)->setSourceUrl('javascript:alert(1)');
        $local = (new Video())->setSourceType(Video::SOURCE_EXTERNAL)->setSourceUrl('http://127.0.0.1/video.mp4');
        $safe = (new Video())->setSourceType(Video::SOURCE_EXTERNAL)->setSourceUrl('https://media.example.test/video.mp4');

        self::assertNull($resolver->resolve($javascript, 'example.test'));
        self::assertNull($resolver->resolve($local, 'example.test'));
        self::assertSame(['mode' => 'video', 'url' => 'https://media.example.test/video.mp4'], $resolver->resolve($safe, 'example.test'));
    }

    public function testExternalVideoRejectsCredentialsAndReservedNetworkTargets(): void
    {
        $resolver = $this->resolver();

        $credentialed = (new Video())->setSourceType(Video::SOURCE_EXTERNAL)->setSourceUrl('https://user:secret@media.example.test/video.mp4');
        $metadata = (new Video())->setSourceType(Video::SOURCE_EXTERNAL)->setSourceUrl('http://169.254.169.254/latest/meta-data');
        $loopbackV6 = (new Video())->setSourceType(Video::SOURCE_EXTERNAL)->setSourceUrl('http://[::1]/video.mp4');

        self::assertNull($resolver->resolve($credentialed, 'example.test'));
        self::assertNull($resolver->resolve($metadata, 'example.test'));
        self::assertNull($resolver->resolve($loopbackV6, 'example.test'));
    }

    public function testProviderUrlsWithCredentialsAreRejected(): void
    {
        $resolver = $this->resolver();

        $youtube = (new Video())->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://user:secret@www.youtube.com/watch?v=abcdefghijk');
        $vimeo = (new Video())->setSourceType(Video::SOURCE_VIMEO)->setSourceUrl('https://user:secret@vimeo.com/123456789');
        $twitch = (new Video())->setSourceType(Video::SOURCE_TWITCH)->setSourceUrl('https://user:secret@www.twitch.tv/videos/987654321');

        self::assertNull($resolver->resolve($youtube, 'example.test'));
        self::assertNull($resolver->resolve($vimeo, 'example.test'));
        self::assertNull($resolver->resolve($twitch, 'example.test'));
    }

    public function testUploadedVideoMustUseOwnedOrSafePlaybackUrl(): void
    {
        $resolver = $this->resolver();
        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode('internal')
            ->setOriginalName('video.mp4')
            ->setTitle('Video')
            ->setLocation('/private/video.mp4');
        $video = (new Video())->setSourceType(Video::SOURCE_UPLOAD)->setMediaAsset($asset);

        self::assertNull($resolver->resolve($video, 'example.test'));

        $asset->setLocation('/uploads/media/video/video.mp4');
        self::assertSame(
            ['mode' => 'video', 'url' => '/uploads/media/video/video.mp4'],
            $resolver->resolve($video, 'example.test'),
        );
    }

    public function testMismatchedProviderUrlIsRejected(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://example.test/video');

        self::assertNull($this->resolver()->resolve($video, 'example.test'));
    }

    private function resolver(): VideoEmbedResolver
    {
        return new VideoEmbedResolver(new MediaUrlPolicy());
    }
}
