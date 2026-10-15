<?php

declare(strict_types=1);

namespace App\Tests\MediaAccessibility;

use App\Entity\MediaAsset;
use App\MediaAccessibility\MediaAccessibilityReportCsv;
use PHPUnit\Framework\TestCase;

final class MediaAccessibilityReportCsvTest extends TestCase
{
    public function testEmptyExportKeepsTheStableHeader(): void
    {
        self::assertSame("asset_id,mime_type,created_at_utc\r\n", (new MediaAccessibilityReportCsv())->encode([]));
    }

    public function testCsvHasStablePrivacyPreservingHeadersAndNeutralizesFormulaText(): void
    {
        $asset = (new MediaAsset())->setMimeType('=1+1');
        $reflection = new \ReflectionProperty(MediaAsset::class, 'id');
        $reflection->setValue($asset, 42);
        $csv = (new MediaAccessibilityReportCsv())->encode([$asset]);
        $rows = array_map(
            static fn (string $row): array => str_getcsv($row, ',', '"', ''),
            explode("\r\n", trim($csv)),
        );

        self::assertSame(['asset_id', 'mime_type', 'created_at_utc'], $rows[0]);
        self::assertSame('42', $rows[1][0]);
        self::assertSame("'=1+1", $rows[1][1]);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $rows[1][2]);
        self::assertStringNotContainsString('original', $csv);
        self::assertStringNotContainsString('location', $csv);
    }
}
