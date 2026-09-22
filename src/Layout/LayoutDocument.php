<?php

declare(strict_types=1);
namespace App\Layout;

/** @phpstan-type WidgetRow array{id:string,type:string,region:string,enabled:bool,config:array<string,string|int|bool>}
 * @phpstan-type Document array{schema:int,theme:string,options:array<string,string|int|bool>,widgets:list<WidgetRow>}
 */
final readonly class LayoutDocument
{
    /** @param array<string, string|int|bool> $options
     * @param list<array{id:string,type:string,region:string,enabled:bool,config:array<string,string|int|bool>}> $widgets
     * @param list<string> $notices
     */
    public function __construct(public string $theme, public array $options, public array $widgets, public array $notices = []) {}
    /** @return Document */
    public function toArray(): array { return ['schema'=>1,'theme'=>$this->theme,'options'=>$this->options,'widgets'=>$this->widgets]; }
}
