<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\OffsiteBackupStatusReader;
use PHPUnit\Framework\TestCase;

final class OffsiteBackupStatusReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'cms-backup-status-') ?: throw new \RuntimeException('Temporary file failed.');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsSanitizedTargetStatuses(): void
    {
        file_put_contents($this->file, implode("\n", [
            "target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired",
            "primary\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t1\t1",
            "secondary\t20260918T120000Z-0123456789ab\t2026-09-18T12:06:00Z\tfailed\t3\t0",
            '',
        ]));

        $statuses = (new OffsiteBackupStatusReader($this->file))->read();

        self::assertTrue($statuses['primary']->successful);
        self::assertTrue($statuses['primary']->required);
        self::assertSame(1, $statuses['primary']->attempts);
        self::assertFalse($statuses['secondary']->successful);
        self::assertSame('2026-09-18T12:06:00+00:00', $statuses['secondary']->checkedAt->format(DATE_ATOM));
    }

    public function testRejectsTheWholeFileWhenARecordIsMalformed(): void
    {
        file_put_contents($this->file, "target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired\nsecret/value\tbad\tbad\tsuccess\t1\t1\n");

        self::assertSame([], (new OffsiteBackupStatusReader($this->file))->read());
    }

    public function testRejectsUnsafePathsBeforeFilesystemAccess(): void
    {
        self::assertSame([], (new OffsiteBackupStatusReader('relative-status.tsv'))->read());
        self::assertSame([], (new OffsiteBackupStatusReader($this->file."\x00"))->read());
        self::assertSame([], (new OffsiteBackupStatusReader($this->file."\xFF"))->read());
        self::assertSame([], (new OffsiteBackupStatusReader('/'.str_repeat('a', 4096)))->read());
    }

    public function testRejectsSymlinkedStatusFiles(): void
    {
        $link = $this->file.'-link';
        if (!symlink($this->file, $link)) {
            self::markTestSkipped('Symlinks are unavailable in this test environment.');
        }

        try {
            self::assertSame([], (new OffsiteBackupStatusReader($link))->read());
        } finally {
            @unlink($link);
        }
    }

    public function testRejectsOversizedFilesLinesAndRecordCollections(): void
    {
        file_put_contents($this->file, str_repeat('x', 1_048_577));
        self::assertSame([], (new OffsiteBackupStatusReader($this->file))->read());

        file_put_contents($this->file, "target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired\n".str_repeat('a', 257)."\n");
        self::assertSame([], (new OffsiteBackupStatusReader($this->file))->read());

        $rows = ["target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired"];
        for ($index = 0; $index < 1001; ++$index) {
            $rows[] = sprintf(
                "target-%04d\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t1\t0",
                $index,
            );
        }
        file_put_contents($this->file, implode("\n", $rows)."\n");

        self::assertSame([], (new OffsiteBackupStatusReader($this->file))->read());
    }

    public function testRejectsUnsafeNumericEncodingAndDuplicateRecords(): void
    {
        $invalidRows = [
            "primary\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t0\t1",
            "primary\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t1234567890\t1",
            "primary\xFF\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t1\t1",
            "primary\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t1\t1\x00",
            "primary\t20260918T120000Z-0123456789ab\t2026-09-18T12:05:00Z\tsuccess\t1\t1\nprimary\t20260918T120000Z-0123456789ab\t2026-09-18T12:06:00Z\tsuccess\t1\t1",
        ];

        foreach ($invalidRows as $row) {
            file_put_contents($this->file, "target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired\n".$row."\n");

            self::assertSame([], (new OffsiteBackupStatusReader($this->file))->read());
        }
    }

    public function testMissingStatusFileMeansNoRecordedRun(): void
    {
        unlink($this->file);

        self::assertSame([], (new OffsiteBackupStatusReader($this->file))->read());
    }
}
