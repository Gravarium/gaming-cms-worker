<?php
declare(strict_types=1);
namespace App\ContentEditor;
final readonly class ContentBlockPolicy
{
    public function __construct(private ContentBlockDocument $documents,private OwnedMediaReferenceGateway $media){}
    public function normalizeForStorage(string $body): string
    {
        $normalized=$this->documents->normalizeForStorage($body);
        foreach($this->documents->decode($normalized)['blocks'] as $block){if(($block['type']??null)!=='media')continue;$id=(int)$block['assetId'];if($this->media->resolve($id)===null)throw new \InvalidArgumentException('Die Medienreferenz #'.$id.' ist nicht verfügbar oder nicht für Content freigegeben.');}
        return $normalized;
    }
}
