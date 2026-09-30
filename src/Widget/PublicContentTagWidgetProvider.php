<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\Content\PublicContentTagQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicContentTagWidgetProvider implements WidgetProvider
{
    public const KEY = 'content.tags';

    public function __construct(
        private PublicContentTagQuery $tags,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return list<WidgetDefinition>
     */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Content-Tags',
                'content',
                'widget/public_content_tags.html.twig',
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
        $count = $config['count'] ?? PublicContentTagQuery::DEFAULT_LIMIT;
        if (!is_int($count) || $count < 1 || $count > PublicContentTagQuery::MAX_ITEMS) {
            $count = PublicContentTagQuery::DEFAULT_LIMIT;
        }

        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.self::KEY;
        $tags = $request?->attributes->get($cacheKey);
        if (!is_array($tags)) {
            $tags = $this->tags->findPublic(PublicContentTagQuery::MAX_ITEMS);
            $request?->attributes->set($cacheKey, $tags);
        }

        return ['items' => array_slice($tags, 0, $count)];
    }
}
