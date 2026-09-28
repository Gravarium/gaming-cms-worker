<?php

declare(strict_types=1);

namespace App\Tests\GameReleaseFeed;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use App\GameReleaseFeed\IcalendarBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class IcalendarBuilderTest extends TestCase
{
    public function testEmptyFeedIsAValidCalendar(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::never())->method('generate');

        $body = (new IcalendarBuilder($urls))->build([], new \DateTimeImmutable('2026-09-28T12:00:00+00:00'));

        self::assertSame(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Gravarium Gaming CMS//Game Release Calendar//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nEND:VCALENDAR\r\n",
            $body,
        );
    }

    public function testEventTextIsEscapedAndFoldedWithoutBreakingUtf8(): void
    {
        $game = (new Game())
            ->setName("Finale, R&D;\r\nBEGIN:VEVENT ".str_repeat('Ö', 45))
            ->setSlug('calendar-test');
        $entry = new GameCatalogueEntry($game);
        $platform = new GamePlatform('PC, Console', 'pc-console');
        $edition = new GameEdition($entry, 'Deluxe; Edition');
        $release = $this->releaseMock($entry, $platform, $edition);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('app_game_catalogue_show', ['slug' => 'calendar-test'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://games.example.test/games/calendar-test');

        $body = (new IcalendarBuilder($urls))->build(
            [$release],
            new \DateTimeImmutable('2026-09-28T12:34:56+00:00'),
        );

        self::assertStringContainsString("UID:game-release-42@gaming-cms\r\n", $body);
        self::assertStringContainsString("DTSTAMP:20260928T123456Z\r\n", $body);
        self::assertStringContainsString('SUMMARY:Finale\, R&D\;\nBEGIN:VEVENT', $body);
        self::assertStringContainsString('URL:https://games.example.test/games/calendar-test', $body);
        self::assertSame(1, substr_count($body, "\r\nBEGIN:VEVENT\r\n"));

        foreach (explode("\r\n", $body) as $line) {
            if ($line !== '') {
                self::assertLessThanOrEqual(75, strlen($line));
            }
        }

        $unfolded = preg_replace("/\r\n /", '', $body);
        self::assertIsString($unfolded);
        self::assertTrue(mb_check_encoding($unfolded, 'UTF-8'));
    }

    private function releaseMock(
        GameCatalogueEntry $entry,
        GamePlatform $platform,
        GameEdition $edition,
    ): GameRelease {
        $release = $this->getMockBuilder(GameRelease::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getEntry', 'getEdition', 'getPlatform', 'getRegion', 'getReleaseAt'])
            ->getMock();
        $release->method('getId')->willReturn(42);
        $release->method('getEntry')->willReturn($entry);
        $release->method('getEdition')->willReturn($edition);
        $release->method('getPlatform')->willReturn($platform);
        $release->method('getRegion')->willReturn("EU,\r\nX-ATTACK: injected");
        $release->method('getReleaseAt')->willReturn(new \DateTimeImmutable('2030-11-01T18:00:00+02:00'));

        return $release;
    }
}
