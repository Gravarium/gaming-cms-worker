<?php
declare(strict_types=1);
namespace App\ContentEditor;
final class ContentBlockDocument
{
    public const PREFIX="cms-blocks:v1\n"; public const VERSION=1; private const MAX_DOCUMENT_BYTES=60000; private const MAX_BLOCKS=100;
    /** @return array{version:int,blocks:list<array<string,mixed>>} */
    public function decode(string $body): array
    {
        if(!str_starts_with($body,self::PREFIX)) return ['version'=>self::VERSION,'blocks'=>$this->legacyBlocks($body)];
        if(strlen($body)>self::MAX_DOCUMENT_BYTES) throw new \InvalidArgumentException('Das Editor-Dokument ist zu groß.');
        try{$decoded=json_decode(substr($body,strlen(self::PREFIX)),true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('Das Editor-Dokument ist kein gültiges JSON.',0,$e);}
        if(!is_array($decoded)||($decoded['version']??null)!==self::VERSION||!is_array($decoded['blocks']??null)) throw new \InvalidArgumentException('Unbekannte Editor-Dokumentversion.');
        /** @var array<int,mixed> $blocks */ $blocks=$decoded['blocks']; if(count($blocks)>self::MAX_BLOCKS) throw new \InvalidArgumentException('Das Editor-Dokument enthält zu viele Blöcke.');
        $normalized=[]; foreach($blocks as $block){if(!is_array($block)) throw new \InvalidArgumentException('Ungültiger Editor-Block.'); /** @var array<string,mixed> $block */ $normalized[]=$this->normalizeBlock($block);}
        return ['version'=>self::VERSION,'blocks'=>$normalized];
    }
    public function normalizeForStorage(string $body): string
    {
        $document=$this->decode($body); if($document['blocks']===[]) throw new \InvalidArgumentException('Der Inhalt darf nicht leer sein.');
        try{$json=json_encode($document,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \InvalidArgumentException('Das Editor-Dokument konnte nicht gespeichert werden.',0,$e);}
        $encoded=self::PREFIX.$json; if(strlen($encoded)>self::MAX_DOCUMENT_BYTES) throw new \InvalidArgumentException('Das Editor-Dokument ist zu groß.'); return $encoded;
    }
    public function plainText(string $body): string
    {
        $parts=[]; foreach($this->decode($body)['blocks'] as $block){switch($block['type']){case 'text':case 'heading':$parts[]=(string)$block['text'];break;case 'quote':$parts[]=(string)$block['text'];if(($block['cite']??'')!=='')$parts[]=(string)$block['cite'];break;case 'link':$parts[]=(string)$block['text'];break;case 'list':foreach($block['items'] as $item)$parts[]=(string)$item;break;case 'media':if(($block['caption']??'')!=='')$parts[]=(string)$block['caption'];if(($block['alt']??'')!=='')$parts[]=(string)$block['alt'];break;}} return trim(implode("\n",$parts));
    }
    /** @return list<array<string,mixed>> */
    private function legacyBlocks(string $body): array
    {
        $body=trim($body); if($body==='')return []; $parts=preg_split('/\R{2,}/u',$body)?:[$body];$blocks=[];
        foreach($parts as $part){$text=$this->boundedText($part,8000,'Textblock');if($text!=='')$blocks[]=['type'=>'text','text'=>$text];if(count($blocks)>=self::MAX_BLOCKS)throw new \InvalidArgumentException('Der vorhandene Inhalt enthält zu viele Abschnitte für den Block-Editor.');}return $blocks;
    }
    /** @param array<string,mixed> $block @return array<string,mixed> */
    private function normalizeBlock(array $block): array
    {
        $type=is_string($block['type']??null)?$block['type']:'';
        return match($type){
            'text'=>['type'=>'text','text'=>$this->requiredText($block['text']??null,8000,'Textblock')],
            'heading'=>['type'=>'heading','level'=>in_array($block['level']??null,[2,3],true)?(int)$block['level']:2,'text'=>$this->requiredText($block['text']??null,500,'Überschrift')],
            'list'=>['type'=>'list','style'=>($block['style']??null)==='ordered'?'ordered':'unordered','items'=>$this->normalizeItems($block['items']??null)],
            'quote'=>['type'=>'quote','text'=>$this->requiredText($block['text']??null,4000,'Zitat'),'cite'=>$this->optionalText($block['cite']??null,300,'Quellenangabe')],
            'link'=>['type'=>'link','text'=>$this->requiredText($block['text']??null,500,'Linktext'),'url'=>$this->normalizeLink($block['url']??null)],
            'media'=>['type'=>'media','assetId'=>$this->positiveInt($block['assetId']??null),'alt'=>$this->optionalText($block['alt']??null,300,'Alternativtext'),'caption'=>$this->optionalText($block['caption']??null,500,'Bildunterschrift')],
            default=>throw new \InvalidArgumentException('Nicht erlaubter Editor-Blocktyp.'),
        };
    }
    /** @return list<string> */
    private function normalizeItems(mixed $value): array{if(!is_array($value)||$value===[]||count($value)>100)throw new \InvalidArgumentException('Eine Liste benötigt 1 bis 100 Einträge.');$items=[];foreach($value as $item)$items[]=$this->requiredText($item,2000,'Listeneintrag');return $items;}
    private function normalizeLink(mixed $value): string{$url=$this->requiredText($value,2048,'Link');if(preg_match('/[\x00-\x1F\x7F]/u',$url)===1||str_contains($url,'\\'))throw new \InvalidArgumentException('Der Link enthält unzulässige Zeichen.');if(str_starts_with($url,'/')&&!str_starts_with($url,'//'))return $url;$parts=parse_url($url);if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new \InvalidArgumentException('Der Link ist nicht erlaubt.');if(!in_array(strtolower((string)$parts['scheme']),['http','https'],true))throw new \InvalidArgumentException('Für Links sind nur HTTP und HTTPS erlaubt.');return $url;}
    private function positiveInt(mixed $value): int{if(is_int($value))$id=$value;elseif(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)===1)$id=(int)$value;else$id=0;if($id<1)throw new \InvalidArgumentException('Medienreferenzen benötigen eine gültige Asset-ID.');return $id;}
    private function requiredText(mixed $value,int $max,string $label): string{if(!is_string($value))throw new \InvalidArgumentException($label.' ist ungültig.');$text=$this->boundedText($value,$max,$label);if($text==='')throw new \InvalidArgumentException($label.' darf nicht leer sein.');return $text;}
    private function optionalText(mixed $value,int $max,string $label): string{if($value===null||$value==='')return '';if(!is_string($value))throw new \InvalidArgumentException($label.' ist ungültig.');return $this->boundedText($value,$max,$label);}
    private function boundedText(string $value,int $max,string $label): string{$value=trim(str_replace(["\r\n","\r"],"\n",$value));if(mb_strlen($value)>$max)throw new \InvalidArgumentException($label.' ist zu lang.');return $value;}
}
