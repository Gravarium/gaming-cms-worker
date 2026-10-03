<?php
declare(strict_types=1);
namespace App\ContentEditor;
use App\NewsEditor\RichDocument;
final readonly class ContentBlockPolicy
{
    public function __construct(private ContentBlockDocument $documents,private OwnedMediaReferenceGateway $media){}
    public function normalizeForStorage(string $body): string
    {
        if (str_starts_with($body, RichDocument::PREFIX)) {
            $rich = new RichDocument();
            $normalized = $rich->normalizeForStorage($body);
            foreach ($rich->mediaIds($normalized) as $id) {
                if ($this->media->resolve($id) === null) {
                    throw new \InvalidArgumentException('Die Medienreferenz #'.$id.' ist nicht verfügbar oder nicht für Content freigegeben.');
                }
            }
            return $normalized;
        }
        $normalized=$this->documents->normalizeForStorage($body);
        foreach($this->documents->decode($normalized)['blocks'] as $block){if(($block['type']??null)!=='media')continue;$id=(int)$block['assetId'];if($this->media->resolve($id)===null)throw new \InvalidArgumentException('Die Medienreferenz #'.$id.' ist nicht verfügbar oder nicht für Content freigegeben.');}
        return $normalized;
    }

    public function plainText(string $document): string
    {
        if (str_starts_with($document, RichDocument::PREFIX)) {
            return (new RichDocument())->plainText($document);
        }
        return $this->documents->plainText($document);
    }
}
