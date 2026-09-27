<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\GameCatalogue\PublicGameCatalogueQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicGameCatalogueWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.game-catalogue';

    public function __construct(
        private PublicGameCatalogueQuery $catalogue,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Spielekatalog',
                'gaming',
                'widget/public_game_catalogue.html.twig',
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
        $count = $config['count'] ?? PublicGameCatalogueQuery::DEFAULT_LIMIT;
        if (!is_int($count) || $count < 1 || $count > PublicGameCatalogueQuery::MAX_ITEMS) {
            $count = PublicGameCatalogueQuery::DEFAULT_LIMIT;
        }

        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.self::KEY;
        $entries = $request?->attributes->get($cacheKey);
        if (!is_array($entries)) {
            $entries = $this->catalogue->findPublic(PublicGameCatalogueQuery::MAX_ITEMS);
            $request?->attributes->set($cacheKey, $entries);
        }

        return ['items' => array_slice($entries, 0, $count)];
    }
}
