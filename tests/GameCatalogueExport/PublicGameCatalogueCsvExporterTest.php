<?php

declare(strict_types=1);

namespace App\Tests\GameCatalogueExport;

use App\GameCatalogueExport\PublicGameCatalogueCsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicGameCatalogueCsvExporterTest extends TestCase
{
    #[DataProvider('formulaValues')]
    public function testSpreadsheetFormulaValuesAreNeutralized(string $value): void
    {
        self::assertSame("'".$value, PublicGameCatalogueCsvExporter::spreadsheetSafeCell($value));
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
        self::assertSame($value, PublicGameCatalogueCsvExporter::spreadsheetSafeCell($value));
    }

    public static function ordinaryValues(): iterable
    {
        yield 'plain' => ['A Normal Game'];
        yield 'empty' => [''];
        yield 'punctuation not a formula' => ['(1999)'];
    }
}
