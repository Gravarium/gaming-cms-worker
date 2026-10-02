<?php

declare(strict_types=1);

namespace App\Widget;

final class GamePublisherDirectoryWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-publisher-directory';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Spiele-Publisher',
            'gaming',
            'widget/game_publisher_directory.html.twig',
        )];
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        return [];
    }
}
