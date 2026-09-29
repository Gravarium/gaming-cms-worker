<?php

declare(strict_types=1);

namespace App\Tests\GuildEventCalendarFeed;

use App\Entity\GuildEvent;
use App\GuildEventCalendarFeed\PublicGuildEventCalendarBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PublicGuildEventCalendarBuilderTest extends TestCase
{
    public function testEmptyFeedIsAValidCalendar(): void
    {
        $calendar = (new PublicGuildEventCalendarBuilder())->build([]);

        self::assertSame(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Gravarium//Gaming CMS Public Guild Events//EN\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:Public guild events\r\nEND:VCALENDAR\r\n",
            $calendar,
        );
    }

    public function testEscapesTextAndUsesStableUidAndUtcTimes(): void
    {
        $event = $this->event(
            41,
            "Raid, alpha; ready\r\nEND:VEVENT\r\nATTENDEE:attacker@example.test",
            new DateTimeImmutable('2030-05-06T07:08:09+05:30'),
            null,
        );
        $builder = new PublicGuildEventCalendarBuilder();
        $calendar = $builder->build([$event]);

        self::assertStringContainsString('UID:guild-event-41@gaming-cms', $calendar);
        self::assertStringContainsString('DTSTART:20300506T013809Z', $calendar);
        self::assertStringContainsString('SUMMARY:Raid\\, alpha\\; ready\\nEND:VEVENT\\nATTENDEE:attacker@example.test', $calendar);
        self::assertStringNotContainsString("\r\nATTENDEE:attacker@example.test\r\n", $calendar);
        self::assertStringNotContainsString('DTEND:', $calendar);
        self::assertSame($calendar, $builder->build([$event]));
    }

    public function testLongUnicodeLinesAreFoldedAtSeventyFiveOctetsAndOnlyTwelveEventsAreEmitted(): void
    {
        $events = [];
        for ($id = 1; $id <= 13; ++$id) {
            $events[] = $this->event(
                $id,
                str_repeat('龍🗡️', 30),
                new DateTimeImmutable('2030-05-06T07:08:09+05:30'),
                new DateTimeImmutable('2030-05-06T09:08:09+05:30'),
            );
        }

        $calendar = (new PublicGuildEventCalendarBuilder())->build($events);
        $lines = explode("\r\n", rtrim($calendar, "\r\n"));

        foreach ($lines as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        }

        self::assertSame(12, substr_count($calendar, "BEGIN:VEVENT\r\n"));
        self::assertStringContainsString('UID:guild-event-12@gaming-cms', $calendar);
        self::assertStringNotContainsString('UID:guild-event-13@gaming-cms', $calendar);
        self::assertStringContainsString('DTEND:20300506T033809Z', $calendar);
    }

    public function testUnsupportedAsciiControlCharactersAreRemovedFromCalendarText(): void
    {
        $event = $this->event(
            42,
            "Raid\0\x01\x0B\x7F\t ready",
            new DateTimeImmutable('2030-05-06T07:08:09+00:00'),
            null,
        );
        $calendar = (new PublicGuildEventCalendarBuilder())->build([$event]);

        self::assertStringContainsString("SUMMARY:Raid\t ready", $calendar);
        self::assertStringNotContainsString("\0", $calendar);
        self::assertStringNotContainsString("\x01", $calendar);
        self::assertStringNotContainsString("\x0B", $calendar);
        self::assertStringNotContainsString("\x7F", $calendar);
    }

    private function event(
        int $id,
        string $title,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): GuildEvent {
        return new class($id, $title, $startsAt, $endsAt) extends GuildEvent {
            public function __construct(
                private readonly int $calendarId,
                string $title,
                DateTimeImmutable $startsAt,
                ?DateTimeImmutable $endsAt,
            ) {
                parent::__construct();
                $this->setTitle($title)->setStartsAt($startsAt)->setEndsAt($endsAt);
            }

            public function getId(): ?int
            {
                return $this->calendarId;
            }
        };
    }
}
