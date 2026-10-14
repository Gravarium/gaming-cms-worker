<?php

declare(strict_types=1);

namespace App\Tests\ContentRedirect;

use App\ContentRedirect\ContentRedirectCsvImporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentRedirectCsvImporterTest extends TestCase
{
    public function testParsesExportedCsvWithBomAndSkipsUnpublishedRowsAtImportBoundary(): void
    {
        $stream = $this->stream("\xEF\xBB\xBFQuellpfad,Inhaltstyp,Zieltitel,Zielslug,Status\r\n/news/legacy-story,news,Story,target-story,published\r\n/page/draft-page,page,Draft,target-page,unpublished\r\n");
        $rows = (new ContentRedirectCsvImporter())->parse($stream);
        fclose($stream);

        self::assertSame([
            ['type' => 'news', 'source_slug' => 'legacy-story', 'target_slug' => 'target-story', 'status' => 'published'],
            ['type' => 'page', 'source_slug' => 'draft-page', 'target_slug' => 'target-page', 'status' => 'unpublished'],
        ], $rows);
    }

    public function testRejectsMismatchedHeadersAndTypePathPairs(): void
    {
        $importer = new ContentRedirectCsvImporter();
        $badHeader = $this->stream("source,type,title,target,status\n");
        try {
            $importer->parse($badHeader);
            self::fail('A non-export header must fail.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        } finally {
            fclose($badHeader);
        }

        $wrongType = $this->stream("Quellpfad,Inhaltstyp,Zieltitel,Zielslug,Status\n/news/story,page,Story,target,published\n");
        try {
            $importer->parse($wrongType);
            self::fail('A path/type mismatch must fail.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        } finally {
            fclose($wrongType);
        }
    }

    public function testRejectsInvalidSlugsAndRowsBeyondLimit(): void
    {
        $importer = new ContentRedirectCsvImporter();
        $invalidSlug = $this->stream("Quellpfad,Inhaltstyp,Zieltitel,Zielslug,Status\n/news/story,news,Story,../target,published\n");
        try {
            $importer->parse($invalidSlug);
            self::fail('Path-like target slugs must fail.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        } finally {
            fclose($invalidSlug);
        }

        $tooMany = $this->stream("Quellpfad,Inhaltstyp,Zieltitel,Zielslug,Status\n/news/one,news,One,target,published\n/news/two,news,Two,target,published\n");
        try {
            $importer->parse($tooMany, 1);
            self::fail('Input above the configured cap must fail.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        } finally {
            fclose($tooMany);
        }
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
