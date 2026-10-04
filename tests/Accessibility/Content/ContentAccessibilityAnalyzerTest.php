<?php

declare(strict_types=1);

namespace App\Tests\Accessibility\Content;

use App\Accessibility\Content\ContentAccessibilityAnalyzer;
use App\ContentEditor\ContentBlockDocument;
use PHPUnit\Framework\TestCase;

final class ContentAccessibilityAnalyzerTest extends TestCase
{
    public function testCountsImagesWithoutExplicitAlternativeTextAndSkippedHeadingLevels(): void
    {
        $analyzer = new ContentAccessibilityAnalyzer(new ContentBlockDocument());
        $document = $this->document([
            ['type' => 'heading', 'level' => 3, 'text' => 'Übersprungene Stufe'],
            ['type' => 'media', 'assetId' => 7, 'alt' => '   ', 'caption' => 'Bild'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Nächster Abschnitt'],
            ['type' => 'heading', 'level' => 3, 'text' => 'Unterabschnitt'],
            ['type' => 'media', 'assetId' => 8, 'alt' => 'Alternativtext', 'caption' => ''],
        ]);

        self::assertSame([
            'missing_media_alt' => 1,
            'heading_order' => 1,
            'invalid_document' => 0,
        ], $analyzer->analyze($document));
    }

    public function testLegacyTextDoesNotCreateAccessibilityFindings(): void
    {
        $analyzer = new ContentAccessibilityAnalyzer(new ContentBlockDocument());

        self::assertSame([
            'missing_media_alt' => 0,
            'heading_order' => 0,
            'invalid_document' => 0,
        ], $analyzer->analyze("Ein vorhandener Absatz.\n\nEin zweiter Absatz."));
    }

    public function testMalformedAndOversizedDocumentsProduceOneBoundedFinding(): void
    {
        $analyzer = new ContentAccessibilityAnalyzer(new ContentBlockDocument());

        self::assertSame([
            'missing_media_alt' => 0,
            'heading_order' => 0,
            'invalid_document' => 1,
        ], $analyzer->analyze(ContentBlockDocument::PREFIX.'{broken'));

        self::assertSame([
            'missing_media_alt' => 0,
            'heading_order' => 0,
            'invalid_document' => 1,
        ], $analyzer->analyze(str_repeat('x', ContentBlockDocument::MAX_DOCUMENT_BYTES + 1)));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function document(array $blocks): string
    {
        return ContentBlockDocument::PREFIX.json_encode(
            ['version' => ContentBlockDocument::VERSION, 'blocks' => $blocks],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
