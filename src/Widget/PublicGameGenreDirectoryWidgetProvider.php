<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\GameCatalogue\GameGenre;
use App\GameGenreDirectory\PublicGameGenreDirectoryQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final class PublicGameGenreDirectoryWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-genres';

    public function __construct(
        private readonly PublicGameGenreDirectoryQuery $genres,
        private readonly RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Spiele nach Genre',
                'gaming',
                'widget/game_genres.html.twig',
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
        if ($key !== self::KEY) {
            return [];
        }

        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.self::KEY;
        /** @var list<GameGenre>|null $genres */
        $genres = $request?->attributes->get($cacheKey);
        if (!is_array($genres)) {
            $genres = $this->genres->findPublicGenres(1, PublicGameGenreDirectoryQuery::MAX_WIDGET_ITEMS);
            $request?->attributes->set($cacheKey, $genres);
        }

        return ['genres' => array_slice($genres, 0, PublicGameGenreDirectoryQuery::MAX_WIDGET_ITEMS)];
    }
}
