<?php

declare(strict_types=1);

namespace App\Widget;

final class GamePlatformDirectoryWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.game-platform-directory';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Spiele-Plattformen',
            'gaming',
            'widget/game_platform_directory.html.twig',
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
