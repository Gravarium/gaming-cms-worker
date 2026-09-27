<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\VideoPlaylist\PublicVideoPlaylistQuery;

final readonly class PublicVideoPlaylistWidgetProvider implements WidgetProvider
{
    private const KEY = 'video.public-playlists';

    public function __construct(private PublicVideoPlaylistQuery $playlists)
    {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Öffentliche Video-Playlists',
                'video',
                'widget/public_video_playlists.html.twig',
                [],
                true,
                [
                    'count' => [
                        'label' => 'Anzahl Playlists',
                        'type' => 'int',
                        'default' => 6,
                        'min' => 1,
                        'max' => 12,
                    ],
                ],
            ),
        ];
    }

    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $count = $config['count'] ?? 6;
        if (!is_int($count)) {
            $count = 6;
        }

        return ['items' => $this->playlists->find(max(1, min(12, $count)))];
    }
}
