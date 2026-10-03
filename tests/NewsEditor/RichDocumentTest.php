<?php

declare(strict_types=1);

namespace App\Tests\NewsEditor;

use App\NewsEditor\RichDocument;
use PHPUnit\Framework\TestCase;

final class RichDocumentTest extends TestCase
{
    public function testNormalizesTypedRichContentAndExtractsOwnedMedia(): void
    {
        $document = new RichDocument();
        $raw = $this->raw([
            ['type' => 'paragraph', 'content' => [
                ['text' => 'Ein ', 'marks' => []],
                ['text' => 'wichtiger', 'marks' => ['strong']],
                ['text' => ' Link', 'marks' => ['link'], 'href' => 'https://example.test/?a=1&b=2'],
            ]],
            ['type' => 'table', 'rows' => [
                [[['text' => 'Spiel', 'marks' => []]], [['text' => 'Wertung', 'marks' => []]]],
                [[['text' => 'A', 'marks' => []]], [['text' => '9', 'marks' => []]]],
            ]],
            ['type' => 'media', 'assetId' => 17, 'alt' => 'Titelbild', 'caption' => 'Bildquelle'],
            ['type' => 'video', 'provider' => 'vimeo', 'videoId' => '12345', 'caption' => 'Videoquelle'],
            ['type' => 'callout', 'tone' => 'tip', 'content' => [['text' => 'Wichtiger Tipp', 'marks' => ['strong']]]],
            ['type' => 'code', 'language' => 'json', 'text' => '{"score": 9}'],
            ['type' => 'separator'],
        ]);

        $normalized = $document->normalizeForStorage($raw);
        self::assertSame($normalized, $document->normalizeForStorage($normalized));
        self::assertSame([17], $document->mediaIds($normalized));
        self::assertSame("Ein wichtiger Link\nSpiel | Wertung\nA | 9\nBildquelle\nVideoquelle\nWichtiger Tipp\n{\"score\": 9}", $document->plainText($normalized));
    }

    public function testRejectsActiveMarkupMalformedTablesAndUnownedMediaShape(): void
    {
        $bad = [
            [['type' => 'html', 'html' => '<script>alert(1)</script>']],
            [['type' => 'paragraph', 'content' => [['text' => 'x', 'marks' => ['link'], 'href' => 'javascript:alert(1)']]]],
            [['type' => 'paragraph', 'content' => [['text' => 'x', 'marks' => ['link'], 'href' => 'https://user@example.test/']]]],
            [['type' => 'paragraph', 'content' => [['text' => 'x', 'marks' => ['strong'], 'onclick' => 'evil()']]]],
            [[
                'type' => 'paragraph',
                'content' => [
                    ['text' => 'x', 'marks' => [['strong']]],
                ],
            ]],
            [['type' => 'table', 'rows' => [
                [[['text' => 'A', 'marks' => []]], [['text' => 'B', 'marks' => []]]],
                [[['text' => 'C', 'marks' => []]]],
            ]]],
            [['type' => 'media', 'assetId' => '17', 'alt' => '', 'caption' => '']],
            [['type' => 'media', 'assetId' => 17, 'alt' => 'Meaning', 'caption' => '', 'decorative' => true]],
            [['type' => 'media', 'assetId' => 17, 'alt' => '', 'caption' => '', 'decorative' => 'true']],
            [['type' => 'video', 'provider' => 'evil', 'videoId' => 'aaaaaa', 'caption' => '']],
            [['type' => 'video', 'provider' => 'vimeo', 'videoId' => 'not-a-number', 'caption' => '']],
            [['type' => 'callout', 'tone' => 'script', 'content' => [['text' => 'x', 'marks' => []]]]],
            [['type' => 'code', 'language' => 'javascript" onload="bad', 'text' => 'x']],
            [['type' => 'separator', 'onclick' => 'bad()']],
        ];
        foreach ($bad as $blocks) {
            try {
                (new RichDocument())->normalizeForStorage($this->raw($blocks));
                self::fail('Unsafe rich content was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOldMediaDefaultsToInformativeAndDecorativeMediaIsExplicit(): void
    {
        $document = new RichDocument();
        $legacy = $document->decode($this->raw([['type' => 'media', 'assetId' => 17, 'alt' => '', 'caption' => '']]]));
        self::assertFalse($legacy['blocks'][0]['decorative']);

        $decorative = $document->decode($this->raw([['type' => 'media', 'assetId' => 17, 'alt' => '', 'caption' => '', 'decorative' => true]]));
        self::assertTrue($decorative['blocks'][0]['decorative']);
        self::assertSame('', $decorative['blocks'][0]['alt']);
    }

    public function testRejectsExcessiveInputBeforeDecoding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RichDocument())->decode(RichDocument::PREFIX.str_repeat('x', 60000));
    }

    public function testLegacyTableHeaderAndExplicitBodyOnlyTable(): void
    {
        $document = new RichDocument();
        $rows = [[[['text' => '<game>', 'marks' => []]]]];
        self::assertTrue($document->decode($this->raw([['type' => 'table', 'rows' => $rows]]))['blocks'][0]['header']);
        $normalized = $document->normalizeForStorage($this->raw([['type' => 'table', 'rows' => $rows, 'header' => false]]));
        self::assertFalse($document->decode($normalized)['blocks'][0]['header']);
        foreach (['false', 1, null] as $invalid) {
            $this->expectInvalidTableHeader($rows, $invalid);
        }
    }

    private function expectInvalidTableHeader(array $rows, mixed $value): void
    {
        try {
            (new RichDocument())->decode($this->raw([['type' => 'table', 'rows' => $rows, 'header' => $value]]));
            self::fail('Non-boolean table header was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /** @param list<array<string,mixed>> $blocks */
    private function raw(array $blocks): string
    {
        return RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => $blocks], JSON_THROW_ON_ERROR);
    }
}
