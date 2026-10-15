<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\FailedMessageCsvExporter;
use PHPUnit\Framework\TestCase;

final class FailedMessageCsvExporterTest extends TestCase
{
    public function testExportsOnlySafeSummaryColumnsInUtcCsv(): void
    {
        $exporter = new FailedMessageCsvExporter();

        $csv = $exporter->export([[
            'id' => '27',
            'type' => 'SendEmailMessage',
            'createdAt' => new \DateTimeImmutable('2026-10-14 14:00:00', new \DateTimeZone('Europe/Berlin')),
            'bytes' => 1536,
        ]]);

        $rows = $this->csvRows($csv);
        self::assertSame(['Message ID', 'Nachrichtentyp', 'Fehlgeschlagen seit (UTC)', 'Nutzlastgröße (Bytes)'], $rows[0]);
        self::assertSame(['27', 'SendEmailMessage', '2026-10-14 12:00:00', '1536'], $rows[1]);
        self::assertStringNotContainsString('recipient', $csv);
        self::assertStringNotContainsString('private body', $csv);
        self::assertStringNotContainsString('exception', $csv);
    }

    public function testNeutralizesFormulaLeadingCells(): void
    {
        $exporter = new FailedMessageCsvExporter();
        $csv = $exporter->export([[
            'id' => '=1+1',
            'type' => "\t@HYPERLINK(\"https://invalid.example\")",
            'createdAt' => new \DateTimeImmutable('2026-10-14 12:00:00', new \DateTimeZone('UTC')),
            'bytes' => 1,
        ]]);

        $rows = $this->csvRows($csv);
        self::assertSame("'=1+1", $rows[1][0]);
        self::assertSame("'\t@HYPERLINK(\"https://invalid.example\")", $rows[1][1]);
    }

    /** @return list<list<string>> */
    private function csvRows(string $csv): array
    {
        $lines = array_filter(explode("\r\n", rtrim($csv, "\r\n")), static fn (string $line): bool => $line !== '');

        return array_values(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines));
    }
}
