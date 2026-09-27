<?php

declare(strict_types=1);

namespace App\Widget;

final class VideoSearchWidgetProvider implements WidgetProvider
{
    private const KEY = 'video.search';

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Videosuche',
                'video',
                'widget/video_search.html.twig',
            ),
        ];
    }

    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        return [];
    }
}
