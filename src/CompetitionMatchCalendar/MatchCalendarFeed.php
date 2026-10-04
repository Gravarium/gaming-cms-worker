<?php

declare(strict_types=1);

namespace App\CompetitionMatchCalendar;

use App\Entity\Competition\CompetitionMatch;

final class MatchCalendarFeed
{
    /**
     * @param list<CompetitionMatch> $matches
     * @param callable(int): string $competitionUrl
     */
    public function render(array $matches, callable $competitionUrl): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Gravarium Gaming CMS//Competition Matches//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];

        foreach ($matches as $match) {
            $competition = $match->getCompetition();
            $date = $match->getScheduledAt();
            if ($match->getId() === null || $competition?->getId() === null || $date === null) {
                continue;
            }

            array_push($lines,
                'BEGIN:VEVENT',
                'UID:competition-match-'.$match->getId().'@gaming-cms',
                'DTSTAMP:'.$this->utc($match->getCreatedAt()),
                'DTSTART:'.$this->utc($date),
                'DURATION:PT1H',
                'SUMMARY:'.$this->escape('Spiel: '.$competition->getName()),
                'DESCRIPTION:'.$this->escape('Runde '.$match->getRoundNumber().' · '.$competition->getGame()?->getName()),
                'URL:'.$competitionUrl($competition->getId()),
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
    }

    private function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    private function fold(string $line): string
    {
        $chunks = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && isset($line[$cut]) && (ord($line[$cut]) & 0xC0) === 0x80) {
                --$cut;
            }
            $chunks[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74;
        }
        $chunks[] = $line;

        return implode("\r\n ", $chunks);
    }
}
