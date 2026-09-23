<?php
declare(strict_types=1);
namespace App\ContentEditor;
use Twig\Extension\AbstractExtension;use Twig\TwigFilter;
final class ContentEditorTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ContentBlockRenderer $renderer,private readonly ContentBlockDocument $documents){}
    /** @return list<TwigFilter> */
    public function getFilters(): array{return [new TwigFilter('content_blocks_render',$this->renderer->render(...),['is_safe'=>['html']]),new TwigFilter('content_blocks_reading_minutes',$this->readingMinutes(...))];}
    public function readingMinutes(string $body): int{try{$text=$this->documents->plainText($body);}catch(\InvalidArgumentException){$text=$body;}$words=preg_split('/\s+/u',trim($text))?:[];return max(1,(int)ceil(count(array_filter($words))/220));}
}
