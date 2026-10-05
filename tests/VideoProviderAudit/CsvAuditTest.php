<?php

declare(strict_types=1);

namespace App\Tests\VideoProviderAudit;

use App\VideoProviderAudit\CsvAudit;
use PHPUnit\Framework\TestCase;

final class CsvAuditTest extends TestCase
{
    public function testWritesStableRfcCompatibleColumnsAndQuotesDelimiterQuotesAndNewlines(): void
    {
        $csv = (new CsvAudit())->encode([[
            'source_id' => 7,
            'provider' => "relay, \"quoted\"\nsecond line",
            'enabled' => true,
            'authorized' => false,
            'status' => 'configured',
            'mode' => null,
        ]]);

        self::assertSame(
            "source_id,provider,enabled,authorized,status,mode\r\n"
            ."7,\"relay, \"\"quoted\"\"\nsecond line\",true,false,configured,\r\n",
            $csv,
        );
    }

    public function testNeutralizesFormulaLikeTextAfterLeadingWhitespace(): void
    {
        $csv = (new CsvAudit())->encode([[
            'source_id' => 8,
            'provider' => '=HYPERLINK("https://example.test",1)',
            'enabled' => false,
            'authorized' => true,
            'status' => " +SUM(1,2)",
            'mode' => '@CMD',
        ]]);

        self::assertSame(
            "source_id,provider,enabled,authorized,status,mode\r\n"
            ."8,\"'=HYPERLINK(\"\"https://example.test\"\",1)\",false,true,\"' +SUM(1,2)\",'@CMD\r\n",
            $csv,
        );
    }

    public function testEmptyExportStillHasTheFixedHeader(): void
    {
        self::assertSame(
            "source_id,provider,enabled,authorized,status,mode\r\n",
            (new CsvAudit())->encode([]),
        );
    }
}
