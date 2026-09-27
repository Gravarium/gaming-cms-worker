<?php

declare(strict_types=1);

namespace App\Widget;

final readonly class PublicGlobalSearchWidgetProvider implements WidgetProvider
{
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'search.global',
                'Globale Suche',
                'core',
                'widget/global_search.html.twig',
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
