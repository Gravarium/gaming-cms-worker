<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\GameCatalogue\GameRelease;
use App\Widget\GameRelease\UpcomingReleaseQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final class UpcomingGameReleaseWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.upcoming-releases';
    private const CACHE_KEY = '_cms_widget_data_gaming_upcoming_releases';
    private const MAX_ITEMS = 12;

    public function __construct(
        private readonly UpcomingReleaseQuery $releases,
        private readonly RequestStack $requests,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Nächste Spielveröffentlichungen',
                'gaming',
                'widget/upcoming_game_releases.html.twig',
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $requestedCount = $config['count'] ?? 6;
        $count = is_int($requestedCount) ? max(1, min(self::MAX_ITEMS, $requestedCount)) : 6;

        $request = $this->requests->getCurrentRequest();
        $releases = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($releases)) {
            $releases = $this->releases->findUpcoming(new \DateTimeImmutable(), self::MAX_ITEMS);
            $request?->attributes->set(self::CACHE_KEY, $releases);
        }

        /** @var list<GameRelease> $releases */
        return ['items' => array_slice($releases, 0, $count)];
    }
}
