<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupPrivateConfigurationStatus;
use PHPUnit\Framework\TestCase;

final class BackupPrivateConfigurationStatusTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'backup-targets-');
        self::assertIsString($this->file);
        chmod($this->file, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReturnsOnlyEnabledCompleteReferencesWithoutSecrets(): void
    {
        file_put_contents($this->file, implode("\n", [
            'backup.dropbox|1|0|rclone:dropbox:cms|/private/password|/private/rclone.conf',
            'backup.box|0|1|rclone:box:cms|/private/password|/private/rclone.conf',
            'backup.sftp|1|1|||',
        ]));

        self::assertSame(
            ['backup.dropbox' => 'ready'],
            (new BackupPrivateConfigurationStatus($this->file))->read(),
        );
    }

    public function testMissingPrivateConfigFileDoesNotReportReady(): void
    {
        file_put_contents($this->file, "backup.dropbox|1|0|repo|/private/password|\n");

        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());
    }

    public function testMalformedOrDuplicatedConfigurationFailsClosed(): void
    {
        file_put_contents($this->file, "backup.dropbox|1|0|repo|password|\nbackup.dropbox|1|0|repo|password|\n");

        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());
    }

    public function testGroupWritableConfigurationFailsClosed(): void
    {
        file_put_contents($this->file, "backup.dropbox|1|0|repo|password|\n");
        chmod($this->file, 0620);
        clearstatcache(true, $this->file);

        self::assertSame([], (new BackupPrivateConfigurationStatus($this->file))->read());
    }
}
