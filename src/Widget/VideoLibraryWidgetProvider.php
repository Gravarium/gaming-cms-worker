<?php

declare(strict_types=1);

namespace App\Widget;

use App\Video\Library\VideoLibraryBrowser;

final readonly class VideoLibraryWidgetProvider implements WidgetProvider
{
    public function __construct(private VideoLibraryBrowser $library)
    {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'video.library',
                'Videothek',
                'video',
                'widget/video_library.html.twig',
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        if ($key !== 'video.library') {
            return [];
        }

        return ['videoCount' => $this->library->countPublic()];
    }
}
