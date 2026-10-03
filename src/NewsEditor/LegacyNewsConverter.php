<?php

declare(strict_types=1);

namespace App\NewsEditor;

use App\ContentEditor\ContentBlockDocument;

/** Reads old articles without changing their stored representation until an editor saves. */
final readonly class LegacyNewsConverter
{
    public function __construct(private ContentBlockDocument $legacy, private RichDocument $rich) {}

    public function forEditing(string $stored): string
    {
        if (str_starts_with($stored, RichDocument::PREFIX)) {
            return $this->rich->normalizeForStorage($stored);
        }
        $blocks = [];
        foreach ($this->legacy->decode($stored)['blocks'] as $block) {
            $text = (string) ($block['text'] ?? '');
            $run = ['text' => $text, 'marks' => []];
            $blocks[] = match ($block['type']) {
                'text' => ['type' => 'paragraph', 'content' => [$run]],
                'heading' => ['type' => 'heading', 'level' => $block['level'], 'content' => [$run]],
                'quote' => ['type' => 'quote', 'content' => [$run], 'cite' => $block['cite']],
                'link' => ['type' => 'paragraph', 'content' => [['text' => $text, 'marks' => ['link'], 'href' => $block['url']]]],
                'list' => ['type' => 'list', 'ordered' => $block['style'] === 'ordered', 'items' => array_map(static fn (string $item): array => [['text' => $item, 'marks' => []]], $block['items'])],
                'media' => ['type' => 'media', 'assetId' => $block['assetId'], 'alt' => $block['alt'], 'caption' => $block['caption'], 'decorative' => false, 'credit' => ''],
                default => throw new \InvalidArgumentException('Nicht unterstützter Inhaltsblock.'),
            };
        }
        if ($blocks === []) {
            $blocks[] = ['type' => 'paragraph', 'content' => [['text' => '', 'marks' => []]]];
        }
        return $this->rich->normalizeForStorage(RichDocument::PREFIX.json_encode(['version' => RichDocument::VERSION, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
