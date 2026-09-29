<?php

declare(strict_types=1);

namespace App\GameReleaseFeed;

use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GameRelease;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class RssFeedBuilder
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<GameRelease> $releases
     */
    public function build(array $releases, \DateTimeImmutable $generatedAt): string
    {
        $channelUrl = $this->urls->generate(
            'app_home',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0">',
            '<channel>',
            '<title>Upcoming game releases</title>',
        ];
        if ($this->isSafeWebUrl($channelUrl)) {
            $lines[] = '<link>'.$this->escapeXml($channelUrl).'</link>';
        }
        $lines[] = '<description>Upcoming releases from enabled games.</description>';
        $lines[] = '<lastBuildDate>'.$this->formatRssDate($generatedAt).'</lastBuildDate>';

        foreach ($releases as $release) {
            $id = $release->getId();
            if ($id === null) {
                continue;
            }

            $entry = $release->getEntry();
            $game = $entry->getGame();
            $platform = $release->getPlatform();
            $edition = $release->getEdition();
            $title = $game->getName();
            if ($edition instanceof GameEdition) {
                $title .= ' - '.$edition->getName();
            }
            $title .= ' - '.$platform->getName().' ('.$release->getRegion().')';

            $itemUrl = $this->urls->generate(
                'app_game_catalogue_show',
                ['slug' => $game->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $lines[] = '<item>';
            $lines[] = '<title>'.$this->escapeXml($title).'</title>';
            if ($this->isSafeWebUrl($itemUrl)) {
                $lines[] = '<link>'.$this->escapeXml($itemUrl).'</link>';
            }
            $lines[] = '<guid isPermaLink="false">'.$this->escapeXml('game-release-'.$id.'@gaming-cms').'</guid>';
            $lines[] = '<pubDate>'.$this->formatRssDate($release->getReleaseAt()).'</pubDate>';
            $description = 'Platform: '.$platform->getName().'; Region: '.$release->getRegion();
            $lines[] = '<description>'.$this->escapeXml($description).'</description>';
            $lines[] = '</item>';
        }

        $lines[] = '</channel>';
        $lines[] = '</rss>';

        return implode("\r\n", $lines)."\r\n";
    }

    private function escapeXml(string $value): string
    {
        $validXml = preg_replace(
            '~[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]~u',
            '',
            $value,
        ) ?? '';

        return htmlspecialchars($validXml, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatRssDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RSS);
    }

    private function isSafeWebUrl(string $url): bool
    {
        return preg_match('/\Ahttps?:\/\/[^\x00-\x20\x7F]+\z/i', $url) === 1;
    }
}
