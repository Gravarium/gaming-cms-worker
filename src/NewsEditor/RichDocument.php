<?php

declare(strict_types=1);

namespace App\NewsEditor;

/**
 * A bounded, typed document. Editor-supplied HTML is never persisted or rendered.
 * The UI may evolve independently of this public-content security boundary.
 */
final class RichDocument
{
    public const PREFIX = "cms-rich:v2\n";
    public const VERSION = 2;
    private const MAX_BYTES = 60000;
    private const MAX_BLOCKS = 100;
    private const MAX_RUNS = 500;

    /** @return array{version:int,blocks:list<array<string,mixed>>} */
    public function decode(string $document): array
    {
        if (strlen($document) > self::MAX_BYTES || !str_starts_with($document, self::PREFIX)) {
            throw new \InvalidArgumentException('Ungültiges oder zu großes Rich-Text-Dokument.');
        }
        try {
            $data = json_decode(substr($document, strlen(self::PREFIX)), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Ungültiges Rich-Text-JSON.', 0, $exception);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Ungültige Rich-Text-Struktur.');
        }
        $this->keys($data, ['version', 'blocks']);
        if ($data['version'] !== self::VERSION || !is_array($data['blocks']) || !array_is_list($data['blocks']) || count($data['blocks']) > self::MAX_BLOCKS) {
            throw new \InvalidArgumentException('Ungültige Rich-Text-Struktur.');
        }
        $runs = 0;
        $blocks = [];
        foreach ($data['blocks'] as $block) {
            if (!is_array($block)) {
                throw new \InvalidArgumentException('Ungültiger Inhaltsblock.');
            }
            $blocks[] = $this->block($block, $runs);
        }
        if ($blocks === []) {
            throw new \InvalidArgumentException('Ein Artikel braucht Inhalt.');
        }
        return ['version' => self::VERSION, 'blocks' => $blocks];
    }

    public function normalizeForStorage(string $document): string
    {
        $encoded = self::PREFIX.json_encode($this->decode($document), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Das Rich-Text-Dokument ist zu groß.');
        }
        return $encoded;
    }

    /** @return list<int> */
    public function mediaIds(string $document): array
    {
        $ids = [];
        foreach ($this->decode($document)['blocks'] as $block) {
            if ($block['type'] === 'media') {
                $ids[] = $block['assetId'];
            }
        }
        return array_values(array_unique($ids));
    }

    public function plainText(string $document): string
    {
        $parts = [];
        foreach ($this->decode($document)['blocks'] as $block) {
            if (isset($block['content'])) {
                $parts[] = $this->runsText($block['content']);
                if ($block['type'] === 'quote' && $block['cite'] !== '') {
                    $parts[] = $block['cite'];
                }
            } elseif ($block['type'] === 'list') {
                foreach ($block['items'] as $item) {
                    $parts[] = $this->runsText($item);
                }
            } elseif ($block['type'] === 'table') {
                foreach ($block['rows'] as $row) {
                    $parts[] = implode(' | ', array_map(fn (array $cell): string => $this->runsText($cell), $row));
                }
            } elseif ($block['type'] === 'media') {
                $parts[] = $block['caption'] ?: $block['alt'];
            } elseif ($block['type'] === 'video') {
                $parts[] = $block['caption'];
            } elseif ($block['type'] === 'code') {
                $parts[] = $block['text'];
            }
        }
        return trim(implode("\n", $parts));
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function block(array $block, int &$runs): array
    {
        $type = $block['type'] ?? null;
        if (in_array($type, ['paragraph', 'heading', 'quote'], true)) {
            $keys = $type === 'heading' ? ['type', 'level', 'content'] : ($type === 'quote' ? ['type', 'content', 'cite'] : ['type', 'content']);
            $this->keys($block, $keys);
            $result = ['type' => $type];
            if ($type === 'heading') {
                if (!in_array($block['level'], [2, 3, 4], true)) {
                    throw new \InvalidArgumentException('Ungültige Überschriftenebene.');
                }
                $result['level'] = $block['level'];
            }
            $result['content'] = $this->content($block['content'], $runs);
            if ($type === 'quote') {
                $result['cite'] = $this->text($block['cite'], 300);
            }
            return $result;
        }
        if ($type === 'list') {
            $this->keys($block, ['type', 'ordered', 'items']);
            if (!is_bool($block['ordered']) || !is_array($block['items']) || !array_is_list($block['items']) || count($block['items']) < 1 || count($block['items']) > 100) {
                throw new \InvalidArgumentException('Ungültige Liste.');
            }
            return ['type' => 'list', 'ordered' => $block['ordered'], 'items' => array_map(fn (mixed $item): array => $this->content($item, $runs), $block['items'])];
        }
        if ($type === 'table') {
            $this->keys($block, isset($block['header']) ? ['type', 'rows', 'header'] : ['type', 'rows']);
            if (isset($block['header']) && !is_bool($block['header'])) {
                throw new \InvalidArgumentException('Ungültige Tabellenkopfzeile.');
            }
            if (!is_array($block['rows']) || !array_is_list($block['rows']) || count($block['rows']) < 1 || count($block['rows']) > 20) {
                throw new \InvalidArgumentException('Ungültige Tabelle.');
            }
            $width = null;
            $rows = [];
            foreach ($block['rows'] as $row) {
                if (!is_array($row) || !array_is_list($row) || count($row) < 1 || count($row) > 10 || ($width !== null && count($row) !== $width)) {
                    throw new \InvalidArgumentException('Ungültige Tabellenzeile.');
                }
                $width = count($row);
                $rows[] = array_map(fn (mixed $cell): array => $this->content($cell, $runs), $row);
            }
            // Older v2 documents always rendered the first row as a header.
            return ['type' => 'table', 'rows' => $rows, 'header' => $block['header'] ?? true];
        }
        if ($type === 'callout') {
            $this->keys($block, ['type', 'tone', 'content']);
            if (!in_array($block['tone'], ['info', 'tip', 'warning'], true)) {
                throw new \InvalidArgumentException('Ungültige Infobox.');
            }
            return ['type' => 'callout', 'tone' => $block['tone'], 'content' => $this->content($block['content'], $runs)];
        }
        if ($type === 'code') {
            $this->keys($block, ['type', 'language', 'text']);
            if (!in_array($block['language'], ['plain', 'bash', 'css', 'html', 'javascript', 'json', 'php', 'python'], true)) {
                throw new \InvalidArgumentException('Ungültige Codesprache.');
            }
            return ['type' => 'code', 'language' => $block['language'], 'text' => $this->text($block['text'], 12000)];
        }
        if ($type === 'separator') {
            $this->keys($block, ['type']);
            return ['type' => 'separator'];
        }
        if ($type === 'media') {
            $this->keys($block, ['type', 'assetId', 'alt', 'caption']);
            if (!is_int($block['assetId']) || $block['assetId'] < 1) {
                throw new \InvalidArgumentException('Ungültige Medienreferenz.');
            }
            return ['type' => 'media', 'assetId' => $block['assetId'], 'alt' => $this->text($block['alt'], 300), 'caption' => $this->text($block['caption'], 500)];
        }
        if ($type === 'video') {
            $this->keys($block, ['type', 'provider', 'videoId', 'caption']);
            if (!in_array($block['provider'], ['youtube', 'vimeo'], true) || !is_string($block['videoId'])
                || ($block['provider'] === 'youtube' && preg_match('/\A[A-Za-z0-9_-]{6,20}\z/D', $block['videoId']) !== 1)
                || ($block['provider'] === 'vimeo' && preg_match('/\A[0-9]{1,20}\z/D', $block['videoId']) !== 1)) {
                throw new \InvalidArgumentException('Ungültige Video-Referenz.');
            }
            return ['type' => 'video', 'provider' => $block['provider'], 'videoId' => $block['videoId'], 'caption' => $this->text($block['caption'], 500)];
        }
        throw new \InvalidArgumentException('Nicht erlaubter Rich-Text-Block.');
    }

    /** @return list<array{text:string,marks:list<string>,href?:string}> */
    private function content(mixed $value, int &$runs): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 100) {
            throw new \InvalidArgumentException('Ungültiger Textinhalt.');
        }
        $result = [];
        foreach ($value as $run) {
            if (!is_array($run) || !isset($run['text'], $run['marks']) || !is_array($run['marks']) || !array_is_list($run['marks'])) {
                throw new \InvalidArgumentException('Ungültiger Textabschnitt.');
            }
            $this->keys($run, isset($run['href']) ? ['text', 'marks', 'href'] : ['text', 'marks']);
            $marks = $run['marks'];
            if (count($marks) !== count(array_unique($marks, SORT_REGULAR)) || count(array_filter($marks, static fn (mixed $mark): bool => !is_string($mark) || !in_array($mark, ['strong', 'em', 'underline', 'strike', 'code', 'link'], true))) > 0 || (in_array('link', $marks, true) !== isset($run['href']))) {
                throw new \InvalidArgumentException('Ungültige Textformatierung.');
            }
            $item = ['text' => $this->text($run['text'], 8000), 'marks' => $marks];
            if (isset($run['href'])) {
                $item['href'] = $this->safeUrl($run['href']);
            }
            $result[] = $item;
            if (++$runs > self::MAX_RUNS) {
                throw new \InvalidArgumentException('Zu viele Textabschnitte.');
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed
     */
    private function keys(array $value, array $allowed): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($allowed);
        if ($actual !== $allowed) {
            throw new \InvalidArgumentException('Unbekannte Rich-Text-Eigenschaft.');
        }
    }

    private function text(mixed $value, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Ungültiger oder zu langer Text.');
        }
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private function safeUrl(mixed $value): string
    {
        $url = $this->text($value, 2048);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/u', $url) === 1 || str_starts_with($url, '//')) {
            throw new \InvalidArgumentException('Nicht erlaubter Link.');
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Nicht erlaubter Link.');
        }
        return $url;
    }

    /** @param array<array<string,mixed>> $runs */
    private function runsText(array $runs): string
    {
        return implode('', array_map(static fn (array $run): string => (string) $run['text'], $runs));
    }
}
