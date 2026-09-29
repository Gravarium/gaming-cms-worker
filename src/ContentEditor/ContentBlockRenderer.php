<?php
declare(strict_types=1);
namespace App\ContentEditor;
final readonly class ContentBlockRenderer
{
    public function __construct(private ContentBlockDocument $documents,private OwnedMediaReferenceGateway $media){}
    public function render(string $body): string
    {
        if (strlen($body) > ContentBlockDocument::MAX_DOCUMENT_BYTES) {
            return '<p>Inhalt kann nicht angezeigt werden.</p>';
        }

        try{$blocks=$this->documents->decode($body)['blocks'];}catch(\InvalidArgumentException){return '<p>'.$this->multiline($body).'</p>';}
        $html=[];foreach($blocks as $block)$html[]=$this->renderBlock($block);return implode("\n",array_filter($html,static fn(string $item):bool=>$item!==''));
    }
    /** @param array<string,mixed> $block */
    private function renderBlock(array $block): string{return match($block['type']){'text'=>'<p>'.$this->multiline((string)$block['text']).'</p>','heading'=>sprintf('<h%d>%s</h%d>',(int)$block['level'],$this->escape((string)$block['text']),(int)$block['level']),'list'=>$this->renderList($block),'quote'=>$this->renderQuote($block),'link'=>'<p><a href="'.$this->attr((string)$block['url']).'" rel="noopener noreferrer">'.$this->escape((string)$block['text']).'</a></p>','media'=>$this->renderMedia($block),default=>'',};}
    /** @param array<string,mixed> $block */
    private function renderList(array $block): string{$tag=$block['style']==='ordered'?'ol':'ul';$items=array_map(fn(mixed $item):string=>'<li>'.$this->multiline((string)$item).'</li>',$block['items']);return '<'.$tag.'>'.implode('',$items).'</'.$tag.'>';}
    /** @param array<string,mixed> $block */
    private function renderQuote(array $block): string{$cite=(string)($block['cite']??'');return '<blockquote><p>'.$this->multiline((string)$block['text']).'</p>'.($cite!==''?'<cite>'.$this->escape($cite).'</cite>':'').'</blockquote>';}
    /** @param array<string,mixed> $block */
    private function renderMedia(array $block): string{$asset=$this->media->resolve((int)$block['assetId']);if($asset===null)return '';$alt=(string)($block['alt']??'');if($alt==='')$alt=$asset['title'];$caption=(string)($block['caption']??'');return '<figure class="content-media"><img src="'.$this->attr($asset['url']).'" alt="'.$this->attr($alt).'" loading="lazy" decoding="async" referrerpolicy="no-referrer">'.($caption!==''?'<figcaption>'.$this->escape($caption).'</figcaption>':'').'</figure>';}
    private function multiline(string $value): string{return nl2br($this->escape($value),false);}private function escape(string $value): string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}private function attr(string $value): string{return $this->escape($value);}
}
