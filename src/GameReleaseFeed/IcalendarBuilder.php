<?php

declare(strict_types=1);

namespace App\GameReleaseFeed;

use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GameRelease;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class IcalendarBuilder
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<GameRelease> $releases
     */
    public function build(array $releases, \DateTimeImmutable $generatedAt): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gravarium Gaming CMS//Game Release Calendar//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($releases as $release) {
            $id = $release->getId();
            if ($id === null) {
                continue;
            }

            $entry = $release->getEntry();
            $game = $entry->getGame();
            $platform = $release->getPlatform();
            $edition = $release->getEdition();
            $summary = $game->getName();
            if ($edition instanceof GameEdition) {
                $summary .= ' - '.$edition->getName();
            }
            $summary .= ' - '.$platform->getName().' ('.$release->getRegion().')';
            $url = $this->urls->generate(
                'app_game_catalogue_show',
                ['slug' => $game->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $eventLines = [
                'BEGIN:VEVENT',
                'UID:game-release-'.$id.'@gaming-cms',
                'DTSTAMP:'.$this->formatUtc($generatedAt),
                'DTSTART:'.$this->formatUtc($release->getReleaseAt()),
                'STATUS:TENTATIVE',
                'SUMMARY:'.$this->escapeText($summary),
                'DESCRIPTION:'.$this->escapeText('Platform: '.$platform->getName()."\nRegion: ".$release->getRegion()),
                'LOCATION:'.$this->escapeText($platform->getName().' - '.$release->getRegion()),
            ];
            if (preg_match('/\Ahttps?:\/\/[^\x00-\x20\x7F]+\z/i', $url) === 1) {
                $eventLines[] = 'URL:'.$url;
            }
            $eventLines[] = 'END:VEVENT';
            array_push($lines, ...$eventLines);
        }

        $lines[] = 'END:VCALENDAR';
        $foldedLines = [];
        foreach ($lines as $line) {
            $foldedLines[] = $this->foldCalendarLine($line);
        }

        return implode("\r\n", $foldedLines)."\r\n";
    }

    private function formatUtc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
    }

    private function escapeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $value,
        );
    }

    private function foldCalendarLine(string $line): string
    {
        $chunks = [];
        $limit = 75;

        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && isset($line[$cut]) && (ord($line[$cut]) & 0xC0) === 0x80) {
                --$cut;
            }
            if ($cut === 0) {
                break;
            }

            $chunks[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74;
        }

        $chunks[] = $line;

        return implode("\r\n ", $chunks);
    }
}
