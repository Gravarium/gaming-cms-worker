<?php

declare(strict_types=1);

namespace App\ContentReleaseFeed;

use App\Entity\ContentRelease;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class PublicContentReleaseRssFeedBuilder
{
    private const ITEM_LIMIT = 50;

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<ContentRelease> $releases
     */
    public function build(array $releases, \DateTimeImmutable $generatedAt): string
    {
        $homeUrl = $this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $selfUrl = $this->urls->generate('app_public_content_release_rss', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '<channel>',
            '<title>Public content releases</title>',
        ];

        if ($this->isSafeWebUrl($homeUrl)) {
            $lines[] = '<link>'.$this->escapeXml($homeUrl).'</link>';
        }
        if ($this->isSafeWebUrl($selfUrl)) {
            $lines[] = '<atom:link href="'.$this->escapeXml($selfUrl).'" rel="self" type="application/rss+xml" />';
        }

        $lines[] = '<description>Published content releases</description>';
        $lines[] = '<lastBuildDate>'.$this->formatRssDate($generatedAt).'</lastBuildDate>';

        foreach (array_slice($releases, 0, self::ITEM_LIMIT) as $release) {
            $id = $release->getId();
            $publishedAt = $release->getPublishedAt();
            if ($id === null || $publishedAt === null) {
                continue;
            }

            $releaseUrl = $this->urls->generate(
                'app_public_content_release_show',
                ['id' => $id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            if (!$this->isSafeWebUrl($releaseUrl)) {
                continue;
            }

            $lines[] = '<item>';
            $lines[] = '<title>'.$this->escapeXml($release->getName()).'</title>';
            $lines[] = '<link>'.$this->escapeXml($releaseUrl).'</link>';
            $lines[] = '<guid isPermaLink="false">'.$this->escapeXml('content-release-'.$id.'@gaming-cms').'</guid>';
            $lines[] = '<pubDate>'.$this->formatRssDate($publishedAt).'</pubDate>';

            $description = $release->getDescription();
            if ($description !== null && $description !== '') {
                $lines[] = '<description>'.$this->escapeXml($description).'</description>';
            }

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
