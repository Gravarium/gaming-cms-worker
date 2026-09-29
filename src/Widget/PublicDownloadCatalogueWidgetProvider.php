<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\DownloadCatalogue\PublicDownloadCatalogueQuery;

final readonly class PublicDownloadCatalogueWidgetProvider implements WidgetProvider
{
    public const KEY = 'downloads.catalogue';

    public function __construct(
        private PublicDownloadCatalogueQuery $catalogue,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Öffentliche Downloads',
                'downloads',
                'widget/public_download_catalogue.html.twig',
                [],
                false,
            ),
        ];
    }

    /**
     * @param array<string, string|int|bool> $config
     *
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $count = $config['count'] ?? 6;

        return [
            'items' => $this->catalogue->findPublicPackages(is_int($count) ? $count : 6),
        ];
    }
}
