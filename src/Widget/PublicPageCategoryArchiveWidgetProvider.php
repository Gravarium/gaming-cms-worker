<?php

declare(strict_types=1);

namespace App\Widget;

use App\PageCategoryArchive\PublicPageCategoryArchiveQuery;

final readonly class PublicPageCategoryArchiveWidgetProvider implements WidgetProvider
{
    public const KEY = 'content.page-categories';
    public const MAX_ITEMS = 12;

    public function __construct(
        private PublicPageCategoryArchiveQuery $pages,
    ) {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Seitenkategorien',
                'content',
                'widget/page_categories.html.twig',
                [],
                true,
                [
                    'count' => [
                        'label' => 'Anzahl',
                        'type' => 'int',
                        'default' => 6,
                        'min' => 1,
                        'max' => self::MAX_ITEMS,
                    ],
                ],
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
        $count = is_int($count) ? max(1, min(self::MAX_ITEMS, $count)) : 6;

        return [
            'categories' => $this->pages->findCategoriesWithPublicPages($count),
        ];
    }
}
