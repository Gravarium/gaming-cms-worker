<?php

declare(strict_types=1);

namespace App\Tests\ContentReleaseExport;

use App\ContentReleaseExport\PublicContentReleaseCsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicContentReleaseCsvExporterTest extends TestCase
{
    #[DataProvider('formulaValues')]
    public function testSpreadsheetFormulaValuesAreNeutralized(string $value): void
    {
        self::assertSame("'".$value, PublicContentReleaseCsvExporter::spreadsheetSafeCell($value));
    }

    public static function formulaValues(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+cmd|A1'];
        yield 'minus' => ['-1+2'];
        yield 'at' => ['@SUM(A1:A2)'];
        yield 'leading whitespace' => ['  =1+1'];
        yield 'leading tab' => ["\t=1+1"];
        yield 'leading carriage return' => ["\r=1+1"];
    }

    #[DataProvider('ordinaryValues')]
    public function testOrdinaryValuesRemainUnchanged(string $value): void
    {
        self::assertSame($value, PublicContentReleaseCsvExporter::spreadsheetSafeCell($value));
    }

    public static function ordinaryValues(): iterable
    {
        yield 'plain' => ['Update 2.1'];
        yield 'empty' => [''];
        yield 'punctuation not a formula' => ['(2026)'];
    }

    public function testExportBoundIsFinite(): void
    {
        self::assertSame(10_000, PublicContentReleaseCsvExporter::MAX_EXPORT_ROWS);
    }
}
