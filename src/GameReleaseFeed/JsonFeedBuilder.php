<?php

declare(strict_types=1);

namespace App\GameReleaseFeed;

use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GameRelease;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class JsonFeedBuilder
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<GameRelease> $releases
     */
    public function build(array $releases): string
    {
        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Upcoming game releases',
            'description' => 'Upcoming releases from enabled games.',
            'items' => [],
        ];
        $homeUrl = $this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $feedUrl = $this->urls->generate('app_game_catalogue_release_json', [], UrlGeneratorInterface::ABSOLUTE_URL);
        if ($this->isSafeWebUrl($homeUrl)) {
            $feed['home_page_url'] = $homeUrl;
        }
        if ($this->isSafeWebUrl($feedUrl)) {
            $feed['feed_url'] = $feedUrl;
        }

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

            $item = [
                'id' => 'game-release-'.$id.'@gaming-cms',
                'title' => $title,
                'content_text' => 'Platform: '.$platform->getName().'; Region: '.$release->getRegion(),
                'date_published' => $release->getReleaseAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            ];
            if ($this->isSafeWebUrl($itemUrl)) {
                $item['url'] = $itemUrl;
            }
            $feed['items'][] = $item;
        }

        return json_encode(
            $feed,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private function isSafeWebUrl(string $url): bool
    {
        return preg_match('/\Ahttps?:\/\/[^\x00-\x20\x7F]+\z/i', $url) === 1;
    }
}
