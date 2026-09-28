<?php

declare(strict_types=1);

namespace App\GuildEventCalendarFeed;

use App\Entity\GuildEvent;
use DateTimeImmutable;
use DateTimeZone;

final class PublicGuildEventCalendarBuilder
{
    private const MAX_EVENTS = 12;

    /**
     * @param list<GuildEvent> $events
     */
    public function build(array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gravarium//Gaming CMS Public Guild Events//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:Public guild events',
        ];

        foreach (array_slice($events, 0, self::MAX_EVENTS) as $event) {
            $id = $event->getId();
            if ($id === null || $id < 1) {
                throw new \InvalidArgumentException('Calendar events must have a persisted identity.');
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:guild-event-'.$id.'@gaming-cms';
            $lines[] = 'DTSTAMP:'.$this->formatUtc($event->getCreatedAt());
            $lines[] = 'DTSTART:'.$this->formatUtc($event->getStartsAt());

            $endsAt = $event->getEndsAt();
            if ($endsAt !== null && $endsAt > $event->getStartsAt()) {
                $lines[] = 'DTEND:'.$this->formatUtc($endsAt);
            }

            $lines[] = 'SUMMARY:'.$this->escapeText($event->getTitle());
            $lines[] = 'CLASS:PUBLIC';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        $folded = [];
        foreach ($lines as $line) {
            $folded[] = $this->foldLine($line);
        }

        return implode("\r\n", $folded)."\r\n";
    }

    private function formatUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
    }

    private function escapeText(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Calendar text must be valid UTF-8.');
        }

        return str_replace(
            ["\\", ';', ',', "\r\n", "\r", "\n"],
            ["\\\\", '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    private function foldLine(string $line): string
    {
        if (!mb_check_encoding($line, 'UTF-8')) {
            throw new \InvalidArgumentException('Calendar lines must be valid UTF-8.');
        }

        if ($line === '') {
            return '';
        }

        $folded = [];
        $offset = 0;
        $length = strlen($line);
        while ($offset < $length) {
            $limit = $offset === 0 ? 75 : 74;
            $chunk = mb_strcut($line, $offset, $limit, 'UTF-8');
            if ($chunk === '') {
                throw new \LogicException('Unable to fold a UTF-8 calendar line.');
            }

            $folded[] = ($offset === 0 ? '' : ' ').$chunk;
            $offset += strlen($chunk);
        }

        return implode("\r\n", $folded);
    }
}
