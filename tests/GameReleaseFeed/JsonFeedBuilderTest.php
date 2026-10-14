<?php

declare(strict_types=1);

namespace App\Tests\GameReleaseFeed;

use App\GameReleaseFeed\JsonFeedBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class JsonFeedBuilderTest extends TestCase
{
    public function testBuildsAValidEmptyJsonFeed11Document(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnMap([
            ['app_home', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://example.test/'],
            ['app_game_catalogue_release_json', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://example.test/games/releases/feed.json'],
        ]);

        $document = json_decode((new JsonFeedBuilder($urls))->build([]), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://jsonfeed.org/version/1.1', $document['version']);
        self::assertSame('https://example.test/', $document['home_page_url']);
        self::assertSame('https://example.test/games/releases/feed.json', $document['feed_url']);
        self::assertSame([], $document['items']);
    }
}
