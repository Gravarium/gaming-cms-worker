<?php

declare(strict_types=1);

namespace App\ContentReleaseFeed;

use App\Entity\ContentRelease;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicContentReleaseJsonFeedBuilder
{
    private const MAX_ITEMS = 50;

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<ContentRelease> $releases
     */
    public function build(array $releases, ?\DateTimeImmutable $now = null): string
    {
        $utc = new \DateTimeZone('UTC');
        $now = ($now ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc);
        $items = [];

        foreach ($releases as $release) {
            $id = $release->getId();
            $publishedAt = $release->getPublishedAt();
            if ($release->getStatus() !== ContentRelease::STATUS_PUBLISHED || $id === null || $id < 1 || $publishedAt === null || $publishedAt > $now) {
                continue;
            }

            $url = $this->urls->generate(
                'app_public_content_release_show',
                ['id' => $id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $items[] = [
                'id' => $url,
                'url' => $url,
                'title' => $release->getName(),
                'content_text' => $release->getDescription() ?? $release->getName(),
                'date_published' => $publishedAt->setTimezone($utc)->format(DATE_ATOM),
            ];
        }

        $items = array_slice($items, 0, self::MAX_ITEMS);
        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Public content releases',
            'home_page_url' => $this->urls->generate('app_public_content_release_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'feed_url' => $this->urls->generate('app_public_content_release_json_feed', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'items' => $items,
        ];

        return json_encode($feed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
