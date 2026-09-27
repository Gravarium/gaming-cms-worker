<?php

declare(strict_types=1);

namespace App\Widget;

final readonly class PublicContentSearchWidgetProvider implements WidgetProvider
{
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'content.search',
                'Inhaltssuche',
                'content',
                'widget/content_search.html.twig',
                [],
                false,
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        return [];
    }
}
