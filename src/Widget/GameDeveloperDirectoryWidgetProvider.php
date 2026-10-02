<?php

declare(strict_types=1);

namespace App\Widget;

final class GameDeveloperDirectoryWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-developer-directory';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Spieleentwickler',
            'gaming',
            'widget/game_developer_directory.html.twig',
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
