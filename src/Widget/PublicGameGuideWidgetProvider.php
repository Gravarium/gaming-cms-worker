<?php

declare(strict_types=1);

namespace App\Widget;

use App\Widget\GameGuide\PublicGameGuideWidgetQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicGameGuideWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.public-guides';
    private const CACHE_KEY = '_cms_widget_data_gaming.public-guides';

    public function __construct(
        private PublicGameGuideWidgetQuery $guides,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Öffentliche Gaming-Guides',
                'gaming',
                'widget/public_guides.html.twig',
                [],
                false,
                [
                    'count' => [
                        'label' => 'Anzahl Guides',
                        'type' => 'int',
                        'default' => 6,
                        'min' => 1,
                        'max' => PublicGameGuideWidgetQuery::MAX_RESULTS,
                    ],
                ],
            ),
        ];
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $request = $this->requests->getCurrentRequest();
        /** @var list<array{id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:string,valid_until:?string,published_at:string,game_name:string,game_slug:string,is_outdated:bool}>|null $guides */
        $guides = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($guides)) {
            $guides = $this->guides->latest(PublicGameGuideWidgetQuery::MAX_RESULTS);
            $request?->attributes->set(self::CACHE_KEY, $guides);
        }

        $count = $config['count'] ?? 6;
        $count = is_int($count) ? max(1, min(PublicGameGuideWidgetQuery::MAX_RESULTS, $count)) : 6;

        return ['guides' => array_slice($guides, 0, $count)];
    }
}
