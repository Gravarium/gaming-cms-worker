<?php

declare(strict_types=1);

namespace App\Widget;

final class GameComparisonWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-comparison';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(self::KEY, 'Spiele vergleichen', 'gaming', 'widget/game_comparison.html.twig')];
    }

    /** @param array<string, string|int|bool> $config
     *  @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        return [];
    }
}
