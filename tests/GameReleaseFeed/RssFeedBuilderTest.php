<?php

declare(strict_types=1);

namespace App\Tests\GameReleaseFeed;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use App\GameReleaseFeed\RssFeedBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RssFeedBuilderTest extends TestCase
{
    public function testEmptyFeedIsAValidRssDocument(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://games.example.test/');

        $xml = (new RssFeedBuilder($urls))->build(
            [],
            new \DateTimeImmutable('2026-09-28T12:34:56+00:00'),
        );
        $document = $this->parseXml($xml);

        self::assertSame('rss', $document->documentElement?->tagName);
        self::assertSame(0, $document->getElementsByTagName('item')->length);
        self::assertStringContainsString('<title>Upcoming game releases</title>', $xml);
        self::assertStringContainsString('<lastBuildDate>Mon, 28 Sep 2026 12:34:56 +0000</lastBuildDate>', $xml);
    }

    public function testItemTextIsXmlEscapedAndUsesStableGuidUtcDateAndPublicLinks(): void
    {
        $game = (new Game())
            ->setName("Finale & <Premiere>\x01")
            ->setSlug('calendar-test');
        $entry = new GameCatalogueEntry($game);
        $platform = new GamePlatform('PC & Console', 'pc-console');
        $edition = new GameEdition($entry, 'Deluxe <edition>');
        $release = $this->releaseMock($entry, $platform, $edition, "EU\r\n<item>Injected</item>");

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::exactly(2))
            ->method('generate')
            ->willReturnMap([
                ['app_home', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://games.example.test/'],
                ['app_game_catalogue_show', ['slug' => 'calendar-test'], UrlGeneratorInterface::ABSOLUTE_URL, 'https://games.example.test/games/calendar-test'],
            ]);

        $xml = (new RssFeedBuilder($urls))->build(
            [$release],
            new \DateTimeImmutable('2026-09-28T12:34:56+00:00'),
        );
        $document = $this->parseXml($xml);

        self::assertSame(1, $document->getElementsByTagName('item')->length);
        self::assertStringContainsString('Finale &amp; &lt;Premiere&gt;', $xml);
        self::assertStringNotContainsString("\x01", $xml);
        self::assertStringContainsString('&lt;item&gt;Injected&lt;/item&gt;', $xml);
        self::assertStringNotContainsString('<item>Injected</item>', $xml);
        self::assertStringContainsString('<guid isPermaLink="false">game-release-42@gaming-cms</guid>', $xml);
        self::assertStringContainsString('<pubDate>Fri, 01 Nov 2030 16:00:00 +0000</pubDate>', $xml);
        self::assertStringContainsString('<link>https://games.example.test/games/calendar-test</link>', $xml);
        self::assertStringContainsString('PC &amp; Console', $xml);
    }

    public function testUnsafeGeneratedItemUrlIsOmitted(): void
    {
        $entry = new GameCatalogueEntry(
            (new Game())->setName('Unsafe URL Game')->setSlug('unsafe-url-game'),
        );
        $platform = new GamePlatform('PC', 'pc');
        $release = $this->releaseMock($entry, $platform, null, 'EU');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::exactly(2))
            ->method('generate')
            ->willReturnOnConsecutiveCalls('https://games.example.test/', "javascript:alert('x')");

        $xml = (new RssFeedBuilder($urls))->build(
            [$release],
            new \DateTimeImmutable('2026-09-28T12:34:56+00:00'),
        );
        $document = $this->parseXml($xml);

        self::assertSame(1, $document->getElementsByTagName('item')->length);
        self::assertSame(1, substr_count($xml, '<link>'));
        self::assertStringNotContainsString('javascript:', $xml);
    }

    private function releaseMock(
        GameCatalogueEntry $entry,
        GamePlatform $platform,
        ?GameEdition $edition,
        string $region,
    ): GameRelease {
        $release = $this->getMockBuilder(GameRelease::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getEntry', 'getEdition', 'getPlatform', 'getRegion', 'getReleaseAt'])
            ->getMock();
        $release->method('getId')->willReturn(42);
        $release->method('getEntry')->willReturn($entry);
        $release->method('getEdition')->willReturn($edition);
        $release->method('getPlatform')->willReturn($platform);
        $release->method('getRegion')->willReturn($region);
        $release->method('getReleaseAt')->willReturn(new \DateTimeImmutable('2030-11-01T18:00:00+02:00'));

        return $release;
    }

    private function parseXml(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($xml, LIBXML_NONET));

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
