<?php

declare(strict_types=1);

namespace App\Tests\Hardware;

use App\Hardware\BenchmarkMeasurement;
use App\Hardware\BenchmarkMethodology;
use App\Hardware\CommunitySetup;
use App\Hardware\ComparisonTable;
use App\Hardware\ProductRevisionHistory;
use App\Hardware\SpecificationValue;
use PHPUnit\Framework\TestCase;

final class HardwareDomainTest extends TestCase
{
    public function testTypedSpecificationAndMethodologyRequireUnitsAndDisclosure(): void
    {
        $spec = new SpecificationValue('gpu.memory', 16, 'GB');
        $method = new BenchmarkMethodology('Raster suite', '2.1', 'Three warm-up and five measured runs.', ['cpu' => 'Test CPU', 'ram' => '32 GB'], 'Retail samples; no paid placement.');
        self::assertSame('GB', $spec->unit);
        self::assertSame('2.1', $method->version);
    }

    public function testComparisonRejectsIncompatibleSeries(): void
    {
        $table = new ComparisonTable();
        $table->add(new BenchmarkMeasurement(1, 'Game X 1440p', 120.0, 'fps', 5));
        $this->expectException(\DomainException::class);
        $table->add(new BenchmarkMeasurement(2, 'Game Y 4K', 80.0, 'fps', 5));
    }

    public function testAccessibleRowsPreserveSourceSeparation(): void
    {
        $table = new ComparisonTable();
        $table->add(new BenchmarkMeasurement(1, 'Game X', 120.0, 'fps', 5, 'editorial'));
        $table->add(new BenchmarkMeasurement(2, 'Game X', 125.0, 'fps', 1, 'community'));
        $rows = $table->accessibleRows();
        self::assertSame('community', $rows[0]['sourceType']);
        self::assertSame('editorial', $rows[1]['sourceType']);
    }

    public function testRevisionHistoryIsAppendOnly(): void
    {
        $history = new ProductRevisionHistory();
        $history->append(2, 'Initial specification', new \DateTimeImmutable('2026-09-24'));
        $history->append(3, 'Corrected memory clock', new \DateTimeImmutable('2026-09-25'));
        self::assertSame(2, $history->revisions()[1]['version']);
    }

    public function testCommunitySetupRequiresModerationForPublication(): void
    {
        self::assertFalse((new CommunitySetup(4, [1, 2], 'My setup', false))->isPublic());
        self::assertTrue((new CommunitySetup(4, [1, 2], 'Verified setup', true))->isPublic());
    }
}
