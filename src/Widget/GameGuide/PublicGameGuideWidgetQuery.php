<?php

declare(strict_types=1);

namespace App\Widget\GameGuide;

use App\GameGuide\PublicGameGuideQuery;

final readonly class PublicGameGuideWidgetQuery
{
    public const MAX_RESULTS = 12;

    public function __construct(private PublicGameGuideQuery $guides)
    {
    }

    /** @return list<array{id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:string,valid_until:?string,published_at:string,game_name:string,game_slug:string,is_outdated:bool}> */
    public function latest(int $limit = 6): array
    {
        return $this->guides->latest(max(1, min(self::MAX_RESULTS, $limit)));
    }
}
