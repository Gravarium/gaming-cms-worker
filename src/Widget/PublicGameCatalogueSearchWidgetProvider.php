<?php

declare(strict_types=1);

namespace App\Widget;

final class PublicGameCatalogueSearchWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-catalogue-search';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Spielekatalog-Suche',
                'gaming',
                'widget/game_catalogue_search.html.twig',
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
