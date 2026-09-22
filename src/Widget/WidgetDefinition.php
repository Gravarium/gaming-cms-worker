<?php

declare(strict_types=1);
namespace App\Widget;

final readonly class WidgetDefinition
{
    /** @param list<string> $regions
     * @param array<string, array{label:string,type:string,default:string|int|bool,choices?:list<string>,min?:int,max?:int}> $settings
     */
    public function __construct(public string $key, public string $label, public string $module, public string $template, public array $regions = [], public bool $multiple = true, public array $settings = [])
    {
        if (preg_match('/^[a-z][a-z0-9.-]{1,59}$/D', $key) !== 1 || !str_starts_with($template, 'widget/') || str_contains($template, '..')) {
            throw new \InvalidArgumentException('Invalid trusted widget definition.');
        }
    }
}
