<?php

declare(strict_types=1);
namespace App\Layout;

use App\Theme\ThemeRegistry;
use App\Widget\WidgetRegistry;

final readonly class LayoutRenderer
{
    public function __construct(private WidgetRegistry $widgets, private ThemeRegistry $themes, private LayoutImages $images) {}
    /** @return array<string, mixed> */
    public function view(LayoutDocument $document): array
    {
        $images=$this->images->resolve($document);
        $regions=[];
        foreach($this->themes->get($document->theme)->regions as $region) $regions[$region]=[];
        foreach($document->widgets as $row) {
            $definition=$this->widgets->get($row['type']);
            if (!$row['enabled'] || $definition===null || !$this->widgets->available($row['type']) || !isset($regions[$row['region']])) continue;
            $regions[$row['region']][]=['id'=>$row['id'],'definition'=>$definition,'config'=>$row['config'],'data'=>$this->widgets->data($row['type'],$row['config']) + ['image'=>$images[$row['config']['imageId']??0]??null]];
        }
        return ['document'=>$document,'regions'=>$regions,'theme'=>$this->themes->get($document->theme)];
    }
}
