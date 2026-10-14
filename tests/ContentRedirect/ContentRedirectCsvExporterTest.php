<?php

declare(strict_types=1);

namespace App\Tests\ContentRedirect;

use App\ContentRedirect\ContentRedirectCsvExporter;
use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use PHPUnit\Framework\TestCase;

final class ContentRedirectCsvExporterTest extends TestCase
{
    public function testWritesFormulaSafeCsvAndOnlyOneHeaderAcrossPages(): void
    {
        $entry = (new ContentEntry())
            ->setTitle('=HYPERLINK("https://attacker.invalid")')
            ->setSlug('safe-target');
        $redirect = new ContentRedirect($entry, ContentEntry::TYPE_NEWS, 'legacy-story');
        $exporter = new ContentRedirectCsvExporter();
        $stream = fopen('php://temp', 'w+b');

        self::assertIsResource($stream);
        $exporter->write([$redirect], $stream, true);
        $exporter->write([$redirect], $stream);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($csv);
        self::assertStringStartsWith("\xEF\xBB\xBFQuellpfad", $csv);
        self::assertSame(1, substr_count($csv, 'Quellpfad'));
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString('/news/legacy-story', $csv);
        self::assertStringContainsString('safe-target', $csv);
        self::assertStringContainsString('unpublished', $csv);
    }

    public function testRequiresWritableStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ContentRedirectCsvExporter())->write([], null);
    }
}
