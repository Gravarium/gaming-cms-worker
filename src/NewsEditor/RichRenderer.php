<?php

declare(strict_types=1);

namespace App\NewsEditor;

use App\ContentEditor\OwnedMediaReferenceGateway;

final readonly class RichRenderer
{
    public function __construct(private RichDocument $documents, private OwnedMediaReferenceGateway $media) {}

    public function render(string $document): string
    {
        $html = [];
        foreach ($this->documents->decode($document)['blocks'] as $block) {
            $html[] = $this->block($block);
        }
        return implode("\n", array_filter($html, static fn (string $part): bool => $part !== ''));
    }

    /** @param array<string,mixed> $block */
    private function block(array $block): string
    {
        $type = $block['type'];
        if (in_array($type, ['paragraph', 'heading', 'quote'], true)) {
            $inner = $this->runs($block['content']);
            $tag = $type === 'paragraph' ? 'p' : ($type === 'quote' ? 'blockquote' : 'h'.$block['level']);
            $cite = $type === 'quote' && $block['cite'] !== '' ? '<cite>'.$this->escape($block['cite']).'</cite>' : '';
            return '<'.$tag.'>'.$inner.$cite.'</'.$tag.'>';
        }
        if ($type === 'list') {
            $tag = $block['ordered'] ? 'ol' : 'ul';
            return '<'.$tag.'>'.implode('', array_map(fn (array $item): string => '<li>'.$this->runs($item).'</li>', $block['items'])).'</'.$tag.'>';
        }
        if ($type === 'table') {
            $rows = [];
            foreach ($block['rows'] as $index => $row) {
                $header = $index === 0 && $block['header'];
                $tag = $header ? 'th' : 'td';
                $scope = $header ? ' scope="col"' : '';
                $rows[] = '<tr>'.implode('', array_map(fn (array $cell): string => '<'.$tag.$scope.'>'.$this->runs($cell).'</'.$tag.'>', $row)).'</tr>';
            }
            $head = $block['header'] ? '<thead>'.$rows[0].'</thead>' : '';
            $body = $block['header'] ? array_slice($rows, 1) : $rows;
            return '<div class="news-table-scroll"><table>'.$head.($body !== [] ? '<tbody>'.implode('', $body).'</tbody>' : '').'</table></div>';
        }
        if ($type === 'callout') {
            return '<aside class="news-callout news-callout--'.$block['tone'].'">'.$this->runs($block['content']).'</aside>';
        }
        if ($type === 'code') {
            return '<pre class="news-code"><code data-language="'.$block['language'].'">'.$this->escape($block['text']).'</code></pre>';
        }
        if ($type === 'separator') {
            return '<hr class="news-separator">';
        }
        if ($type === 'media') {
            $asset = $this->media->resolve($block['assetId']);
            if ($asset === null) {
                return '';
            }
            $alt = ($block['decorative'] ?? false) ? '' : ($block['alt'] !== '' ? $block['alt'] : $asset['title']);
            return '<figure class="content-media"><img src="'.$this->escape($asset['url']).'" alt="'.$this->escape($alt).'" loading="lazy" decoding="async" referrerpolicy="no-referrer">'.($block['caption'] !== '' ? '<figcaption>'.$this->escape($block['caption']).'</figcaption>' : '').'</figure>';
        }
        if ($type === 'video') {
            $url = $block['provider'] === 'youtube'
                ? 'https://www.youtube-nocookie.com/embed/'.$block['videoId']
                : 'https://player.vimeo.com/video/'.$block['videoId'];
            return '<figure class="news-video"><iframe src="'.$this->escape($url).'" title="Video" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>'.($block['caption'] !== '' ? '<figcaption>'.$this->escape($block['caption']).'</figcaption>' : '').'</figure>';
        }
        return '';
    }

    /** @param array<array<string,mixed>> $runs */
    private function runs(array $runs): string
    {
        $parts = [];
        foreach ($runs as $run) {
            $value = nl2br($this->escape((string) $run['text']), false);
            foreach ((array) $run['marks'] as $mark) {
                $tag = match ($mark) { 'strong' => 'strong', 'em' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code', 'link' => 'a', default => throw new \InvalidArgumentException('Ungültige Textformatierung.') };
                if ($tag === 'a' && !isset($run['href'])) {
                    throw new \InvalidArgumentException('Linkziel fehlt.');
                }
                $value = $tag === 'a'
                    ? '<a href="'.$this->escape((string) $run['href']).'" rel="noopener noreferrer">'.$value.'</a>'
                    : '<'.$tag.'>'.$value.'</'.$tag.'>';
            }
            $parts[] = $value;
        }
        return implode('', $parts);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
