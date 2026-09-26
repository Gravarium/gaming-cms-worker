<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final class CalendarExporter
{
    public function export(string $uid, string $title, EventOccurrence $occurrence): string
    {
        if ($uid === '' || mb_strlen($uid) > 180 || $title === '' || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Calendar identity and title are required and bounded.');
        }
        $escape = static fn (string $value): string => str_replace(
            ["\\", ";", ",", "\r", "\n"],
            ["\\\\", '\\;', '\\,', '', '\\n'],
            $value,
        );
        $utc = new \DateTimeZone('UTC');

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gravarium//Guild Events//EN',
            'BEGIN:VEVENT',
            'UID:'.$escape($uid),
            'DTSTART:'.$occurrence->startsAt->setTimezone($utc)->format('Ymd\\THis\\Z'),
            'DTEND:'.$occurrence->endsAt->setTimezone($utc)->format('Ymd\\THis\\Z'),
            'SUMMARY:'.$escape($title),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }
}
