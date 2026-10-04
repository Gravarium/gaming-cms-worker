<?php

declare(strict_types=1);

namespace App\Tests\NewsEditor;

use App\Entity\ContentEntry;
use App\NewsEditor\NewsReadinessReport;
use App\NewsEditor\RichDocument;
use PHPUnit\Framework\TestCase;

final class NewsReadinessReportTest extends TestCase
{
    private NewsReadinessReport $report;
    private RichDocument $documents;

    protected function setUp(): void
    {
        $this->documents = new RichDocument();
        $this->report = new NewsReadinessReport($this->documents);
    }

    public function testReportFindingsAreDeterministicAndNeverReturnDraftText(): void
    {
        $secret = 'UNSAVED_PRIVATE_NEWS_TEXT_674';
        $document = $this->document([
            ['type' => 'heading', 'level' => 3, 'content' => [$this->textRun($secret)]],
            ['type' => 'quote', 'content' => [$this->textRun('Ein kurzes Zitat.')], 'cite' => ''],
            ['type' => 'media', 'assetId' => 7, 'alt' => '', 'caption' => 'Bildunterschrift'],
        ]);

        $first = $this->report->analyze($document, $this->entry());
        $second = $this->report->analyze($document, $this->entry());

        self::assertSame($first, $second);
        self::assertSame(3, $first['metrics']['blockCount']);
        self::assertSame(1, $first['metrics']['imageCount']);
        self::assertContains('structure.first_heading_level', array_column($first['findings'], 'code'));
        self::assertContains('structure.h2_missing', array_column($first['findings'], 'code'));
        self::assertContains('accessibility.image_alt_missing', array_column($first['findings'], 'code'));
        self::assertContains('attribution.quote_missing', array_column($first['findings'], 'code'));
        self::assertContains('metadata.description_missing', array_column($first['findings'], 'code'));
        self::assertStringNotContainsString($secret, json_encode($first, JSON_THROW_ON_ERROR));
        self::assertSame('review_recommended', $first['summary']['state']);
    }

    public function testLongBlocksLongSentencesAndDenseLinksAreReported(): void
    {
        $linked = str_repeat('Linktext ', 200);
        $plain = str_repeat('weiterer Inhalt ', 31).'Ende.';
        $document = $this->document([
            ['type' => 'heading', 'level' => 2, 'content' => [$this->textRun('Hauptabschnitt')]],
            ['type' => 'paragraph', 'content' => [
                $this->textRun($linked, ['link'], 'https://example.test/quelle'),
                $this->textRun($plain),
            ]],
        ]);

        $result = $this->report->analyze($document, $this->entry()->setExcerpt('Eine Kurzbeschreibung.'));
        $codes = array_column($result['findings'], 'code');

        self::assertContains('content.block_long', $codes);
        self::assertContains('readability.sentences_long', $codes);
        self::assertContains('links.density_high', $codes);
        self::assertGreaterThan(25, $result['metrics']['linkDensityPercent']);
    }

    public function testUndescriptiveAndUrlOnlyLinkLabelsAreReportedWithoutReturningTheirText(): void
    {
        $privateUrl = 'https://private.example.test/internal-draft-destination';
        $document = $this->document([
            ['type' => 'heading', 'level' => 2, 'content' => [$this->textRun('Hauptabschnitt')]],
            ['type' => 'paragraph', 'content' => [
                $this->textRun('Hier', ['link'], '/private-draft'),
                $this->textRun(' klicken', ['link'], '/private-draft'),
                $this->textRun(' oder diese Adresse ', []),
                $this->textRun($privateUrl, ['link'], $privateUrl),
            ]],
        ]);

        $result = $this->report->analyze($document, $this->entry()->setExcerpt('Eine Kurzbeschreibung.'));
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertContains('links.descriptive_text', array_column($result['findings'], 'code'));
        self::assertSame(2, $result['metrics']['linkCount']);
        self::assertStringNotContainsString($privateUrl, $serialized);
        self::assertStringNotContainsString('Hier klicken', $serialized);
    }

    public function testReportCapsFindingsAndRejectsMalformedDocuments(): void
    {
        $blocks = array_fill(0, 100, ['type' => 'paragraph', 'content' => [$this->textRun('')]]);
        $result = $this->report->analyze($this->document($blocks), $this->entry());

        self::assertLessThanOrEqual(80, count($result['findings']));
        self::assertContains('report.findings_limited', array_column($result['findings'], 'code'));
        self::assertTrue($result['summary']['limited']);
        $this->expectException(\InvalidArgumentException::class);
        $this->report->analyze('not-a-rich-document', $this->entry());
    }

    public function testDecorativeImageDoesNotReceiveMissingAltFinding(): void
    {
        $document = $this->document([
            ['type' => 'heading', 'level' => 2, 'content' => [$this->textRun('Hauptabschnitt')]],
            ['type' => 'media', 'assetId' => 7, 'alt' => '', 'caption' => '', 'decorative' => true],
        ]);

        $report = $this->report->analyze($document, $this->entry());

        self::assertSame(1, $report['metrics']['imageCount']);
        self::assertNotContains('accessibility.image_alt_missing', array_column($report['findings'], 'code'));
    }

    private function document(array $blocks): string
    {
        $encoded = json_encode(['version' => 2, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->documents->normalizeForStorage(RichDocument::PREFIX.$encoded);
    }

    /** @param list<string> $marks @return array{text:string,marks:list<string>,href?:string} */
    private function textRun(string $text, array $marks = [], ?string $href = null): array
    {
        return $href === null ? ['text' => $text, 'marks' => $marks] : ['text' => $text, 'marks' => $marks, 'href' => $href];
    }

    private function entry(): ContentEntry
    {
        return (new ContentEntry())
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Kurzer Nachrichtentitel')
            ->setSlug('news-readiness-test');
    }
}
