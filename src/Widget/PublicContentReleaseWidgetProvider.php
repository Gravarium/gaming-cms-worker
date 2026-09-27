<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\ContentRelease\PublicContentReleaseQuery;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @phpstan-import-type PublicRelease from PublicContentReleaseQuery
 */
final readonly class PublicContentReleaseWidgetProvider implements WidgetProvider
{
    public const KEY = 'content.releases';

    public function __construct(
        private PublicContentReleaseQuery $releases,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Veröffentlichte Content-Releases',
                'content',
                'widget/public_content_releases.html.twig',
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

        $count = $config['count'] ?? PublicContentReleaseQuery::DEFAULT_LIMIT;
        if (!is_int($count) || $count < 1 || $count > PublicContentReleaseQuery::MAX_RELEASES) {
            $count = PublicContentReleaseQuery::DEFAULT_LIMIT;
        }

        $request = $this->requests->getCurrentRequest();
        $cacheKey = '_cms_widget_data_'.self::KEY;
        $releases = $request?->attributes->get($cacheKey);
        if (!is_array($releases)) {
            $releases = $this->releases->findPublic(PublicContentReleaseQuery::MAX_RELEASES);
            $request?->attributes->set($cacheKey, $releases);
        }

        /** @var list<PublicRelease> $releases */
        return ['releases' => array_slice($releases, 0, $count)];
    }
}