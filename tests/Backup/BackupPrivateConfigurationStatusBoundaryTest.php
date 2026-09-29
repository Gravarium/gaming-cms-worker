<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupPrivateConfigurationStatus;
use PHPUnit\Framework\TestCase;

final class BackupPrivateConfigurationStatusBoundaryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'backup-private-status-') ?: throw new \RuntimeException('Temporary file failed.');
        chmod($this->file, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->file.'-link');
    }

    public function testPreservesReadinessSemanticsWithCommentsAndIncompleteEntries(): void
    {
        file_put_contents($this->file, implode("\n", [
            '# private backup configuration',
            'backup.dropbox|1|0|rclone:dropbox:cms|/private/password|/private/rclone.conf',
            'backup.box|0|1|rclone:box:cms|/private/password|/private/rclone.conf',
            'backup.sftp|1|1|||',
            '',
        ]));

        self::assertSame(
            ['backup.dropbox' => 'ready'],
            (new BackupPrivateConfigurationStatus($this->file))->read(),
        );
    }

    public function testRejectsUnsafePathsAndSymlinkedFiles(): void
    {
        foreach ([
            'relative-status.conf',
            $this->file."\x00",
            $this->file."\xFF",
            '/'.str_repeat('a', 4001),
        ] as $path) {
            self::assertSame([], (new BackupPrivateConfigurationStatus($path))->read());
        }

        if (!symlink($this->file, $this->file.'-link')) {
            self::markTestSkipped('Symlinks are unavailable in this test environment.');
        }

        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file.'-link'))->read());
    }

    public function testRejectsOversizedFilesAndLines(): void
    {
        file_put_contents($this->file, str_repeat('x', 1_048_577));
        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());

        file_put_contents($this->file, 'backup.target|1|0|'.str_repeat('a', 513).'|password|config'."\n");
        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());
    }

    public function testRejectsMalformedEncodingControlsAndDuplicateRecords(): void
    {
        $header = 'backup.target|1|0|repository|password|config';
        foreach ([
            $header."\xFF\n",
            "backup.target|1|0|repository|pass\x00word|config\n",
            $header."\n".$header."\n",
            "backup.target|2|0|repository|password|config\n",
        ] as $contents) {
            file_put_contents($this->file, $contents);

            self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());
        }
    }

    public function testRejectsMoreThanTheBoundedRecordCount(): void
    {
        $rows = [];
        for ($index = 0; $index < 1001; ++$index) {
            $rows[] = sprintf(
                'backup.target-%04d|1|0|repository|password|config',
                $index,
            );
        }
        file_put_contents($this->file, implode("\n", $rows)."\n");

        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());
    }
}
