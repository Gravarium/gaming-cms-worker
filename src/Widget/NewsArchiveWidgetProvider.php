<?php

declare(strict_types=1);

namespace App\Widget;

use App\Repository\PublicNewsArchiveRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class NewsArchiveWidgetProvider implements WidgetProvider
{
    public const KEY = 'content.news-archive';

    private const CACHE_KEY = '_cms_widget_data_content.news-archive';
    private const DEFAULT_COUNT = 6;
    private const MAX_COUNT = 12;

    public function __construct(
        private readonly PublicNewsArchiveRepository $archive,
        private readonly RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'News-Archiv',
                'content',
                'widget/news_archive.html.twig',
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

        $requestedCount = $config['count'] ?? self::DEFAULT_COUNT;
        $count = is_int($requestedCount)
            ? max(1, min(self::MAX_COUNT, $requestedCount))
            : self::DEFAULT_COUNT;

        $request = $this->requests->getCurrentRequest();
        $periods = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($periods)) {
            $periods = $this->archive->availablePeriods(new \DateTimeImmutable());
            $request?->attributes->set(self::CACHE_KEY, $periods);
        }

        /** @var list<array{year:int, month:int, count:int}> $periods */
        return ['periods' => array_slice($periods, 0, $count)];
    }
}
