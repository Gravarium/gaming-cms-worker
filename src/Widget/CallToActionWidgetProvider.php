<?php

declare(strict_types=1);

namespace App\Widget;

final readonly class CallToActionWidgetProvider implements WidgetProvider
{
    /**
     * @var array<string, array{label: string, module: string, route: string}>
     */
    private const DESTINATIONS = [
        'content.call-to-action' => [
            'label' => 'News entdecken',
            'module' => 'content',
            'route' => 'app_news_index',
        ],
        'gaming.call-to-action' => [
            'label' => 'Gilden entdecken',
            'module' => 'gaming',
            'route' => 'app_gaming_index',
        ],
        'video.call-to-action' => [
            'label' => 'Videos entdecken',
            'module' => 'video',
            'route' => 'app_video_index',
        ],
    ];

    public function definitions(): array
    {
        $definitions = [];

        foreach (self::DESTINATIONS as $key => $destination) {
            $definitions[] = new WidgetDefinition(
                $key,
                $destination['label'],
                $destination['module'],
                'widget/call_to_action.html.twig',
                [],
                true,
                [
                    'buttonLabel' => [
                        'label' => 'Button-Beschriftung',
                        'type' => 'text',
                        'default' => 'Mehr erfahren',
                        'max' => 80,
                    ],
                ],
            );
        }

        return $definitions;
    }

    /**
     * @param array<string, string|int|bool> $config
     *
     * @return array<string, string>
     */
    public function data(string $key, array $config): array
    {
        $destination = self::DESTINATIONS[$key] ?? null;

        return $destination === null ? [] : ['route' => $destination['route']];
    }
}
