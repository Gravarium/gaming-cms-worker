<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\Content\PublicContentCategoryQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicContentCategoryWidgetProvider implements WidgetProvider
{
    public const KEY = 'content.categories';

    public function __construct(
        private PublicContentCategoryQuery $categories,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'News-Kategorien',
                'content',
                'widget/public_content_categories.html.twig',
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
        $count = $config['count'] ?? PublicContentCategoryQuery::DEFAULT_LIMIT;
        if (!is_int($count) || $count < 1 || $count > PublicContentCategoryQuery::MAX_ITEMS) {
            $count = PublicContentCategoryQuery::DEFAULT_LIMIT;
        }

        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.self::KEY;
        $categories = $request?->attributes->get($cacheKey);
        if (!is_array($categories)) {
            $categories = $this->categories->findPublic(PublicContentCategoryQuery::MAX_ITEMS);
            $request?->attributes->set($cacheKey, $categories);
        }

        return ['items' => array_slice($categories, 0, $count)];
    }
}
