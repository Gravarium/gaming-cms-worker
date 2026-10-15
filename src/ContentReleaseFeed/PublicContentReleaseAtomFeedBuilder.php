<?php

declare(strict_types=1);

namespace App\ContentReleaseFeed;

use App\Entity\ContentRelease;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicContentReleaseAtomFeedBuilder
{
    private const MAX_ENTRIES = 50;

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<ContentRelease> $releases
     * @return array{
     *     feed_id: string,
     *     feed_url: string,
     *     title: string,
     *     updated: string,
     *     last_modified: ?\DateTimeImmutable,
     *     entries: list<array{id: string, url: string, title: string, summary: string, updated: string}>
     * }
     */
    public function build(array $releases, ?\DateTimeImmutable $now = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = ($now ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc);
        $entries = [];

        foreach ($releases as $release) {
            $publishedAt = $release->getPublishedAt();
            $id = $release->getId();
            if ($release->getStatus() !== ContentRelease::STATUS_PUBLISHED || $id === null || $id < 1 || $publishedAt === null || $publishedAt > $now) {
                continue;
            }

            $url = $this->urls->generate(
                'app_public_content_release_show',
                ['id' => $id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $entries[] = [
                'id' => $url,
                'url' => $url,
                'title' => $release->getName(),
                'summary' => $release->getDescription() ?? $release->getName(),
                'order' => $id,
                'published_at' => $publishedAt->setTimezone($utc),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            $byPublishedAt = $right['published_at'] <=> $left['published_at'];
            return $byPublishedAt !== 0 ? $byPublishedAt : $right['order'] <=> $left['order'];
        });
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);

        $lastModified = $entries[0]['published_at'] ?? null;
        $items = array_map(static fn (array $entry): array => [
            'id' => $entry['id'],
            'url' => $entry['url'],
            'title' => $entry['title'],
            'summary' => $entry['summary'],
            'updated' => $entry['published_at']->format(DATE_ATOM),
        ], $entries);
        $feedUrl = $this->urls->generate('app_public_content_release_atom', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            'feed_id' => $feedUrl,
            'feed_url' => $feedUrl,
            'title' => 'Public content releases',
            'updated' => ($lastModified ?? $now)->format(DATE_ATOM),
            'last_modified' => $lastModified,
            'entries' => $items,
        ];
    }
}
