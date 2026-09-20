<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Video;
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
            (new VideoEmbedResolver())->resolve($video, 'example.test'),
        );
    }

    public function testVimeoUrlIsConvertedToPlayerUrl(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_VIMEO)
            ->setSourceUrl('https://vimeo.com/123456789');

        self::assertSame(
            ['mode' => 'iframe', 'url' => 'https://player.vimeo.com/video/123456789'],
            (new VideoEmbedResolver())->resolve($video, 'example.test'),
        );
    }

    public function testTwitchVideoIncludesCurrentParentHost(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_TWITCH)
            ->setSourceUrl('https://www.twitch.tv/videos/987654321');

        self::assertSame(
            ['mode' => 'iframe', 'url' => 'https://player.twitch.tv/?video=v987654321&parent=gaming.example.test'],
            (new VideoEmbedResolver())->resolve($video, 'gaming.example.test'),
        );
    }

    public function testMismatchedProviderUrlIsRejected(): void
    {
        $video = (new Video())
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://example.test/video');

        self::assertNull((new VideoEmbedResolver())->resolve($video, 'example.test'));
    }
}
