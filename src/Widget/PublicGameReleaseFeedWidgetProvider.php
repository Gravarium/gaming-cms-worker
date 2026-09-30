<?php

declare(strict_types=1);

namespace App\Widget;

final class PublicGameReleaseFeedWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-release-feed';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'RSS-Feed für Spielveröffentlichungen',
                'gaming',
                'widget/game_release_feed.html.twig',
            ),
        ];
    }

    /**
     * @param array<string, string|int|bool> $config
     *
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        return [];
    }
}
