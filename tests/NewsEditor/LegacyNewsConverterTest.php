<?php

declare(strict_types=1);

namespace App\Tests\NewsEditor;

use App\ContentEditor\ContentBlockDocument;
use App\NewsEditor\LegacyNewsConverter;
use App\NewsEditor\RichDocument;
use PHPUnit\Framework\TestCase;

final class LegacyNewsConverterTest extends TestCase
{
    public function testExistingTextLinksQuotesListsAndMediaRemainEditable(): void
    {
        $legacy = new ContentBlockDocument();
        $rich = new RichDocument();
        $stored = $legacy->normalizeForStorage(ContentBlockDocument::PREFIX.json_encode(['version' => 1, 'blocks' => [
            ['type' => 'text', 'text' => 'Erster Absatz'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Details'],
            ['type' => 'link', 'text' => 'Quelle', 'url' => 'https://example.test/'],
            ['type' => 'quote', 'text' => 'Zitat', 'cite' => 'Interview'],
            ['type' => 'list', 'style' => 'unordered', 'items' => ['Eins', 'Zwei']],
            ['type' => 'media', 'assetId' => 7, 'alt' => 'Bild', 'caption' => 'Quelle'],
        ]], JSON_THROW_ON_ERROR));
        $converted = (new LegacyNewsConverter($legacy, $rich))->forEditing($stored);
        $blocks = $rich->decode($converted)['blocks'];

        self::assertSame(['paragraph', 'heading', 'paragraph', 'quote', 'list', 'media'], array_column($blocks, 'type'));
        self::assertSame('https://example.test/', $blocks[2]['content'][0]['href']);
        self::assertSame('Interview', $blocks[3]['cite']);
        self::assertFalse($blocks[5]['decorative']);
        self::assertSame('Quelle', $blocks[5]['caption']);
        self::assertSame('', $blocks[5]['credit']);
        self::assertSame([7], $rich->mediaIds($converted));
        self::assertSame($converted, (new LegacyNewsConverter($legacy, $rich))->forEditing($converted));
    }
}
