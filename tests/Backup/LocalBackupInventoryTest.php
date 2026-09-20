<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\LocalBackupInventory;
use PHPUnit\Framework\TestCase;

final class LocalBackupInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/gaming-cms-backup-inventory-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testReturnsOnlyCompleteValidBackupsNewestFirst(): void
    {
        $this->backup('20260918T030000Z-abcdef123456', '2026-09-18T03:00:00Z');
        $this->backup('20260919T030000Z-fedcba654321', '2026-09-19T03:00:00Z');
        mkdir($this->root.'/unexpected');

        $snapshot = (new LocalBackupInventory($this->root))->read();

        self::assertTrue($snapshot['available']);
        self::assertSame([
            '20260919T030000Z-fedcba654321',
            '20260918T030000Z-abcdef123456',
        ], array_column($snapshot['backups'], 'id'));
        self::assertGreaterThan(0, $snapshot['backups'][0]['bytes']);
    }

    public function testRejectsManifestWithDifferentId(): void
    {
        $this->backup('20260918T030000Z-abcdef123456', '2026-09-18T03:00:00Z', '20260918T030000Z-0000000');

        self::assertSame([], (new LocalBackupInventory($this->root))->read()['backups']);
    }

    private function backup(string $id, string $createdAt, ?string $manifestId = null): void
    {
        $directory = $this->root.'/'.$id;
        mkdir($directory, 0700);
        file_put_contents($directory.'/database.dump', 'database');
        file_put_contents($directory.'/uploads.tar.gz', 'uploads');
        file_put_contents($directory.'/checksums.sha256', 'checksums');
        file_put_contents($directory.'/manifest.txt', implode("\n", [
            'format_version=1',
            'backup_id='.($manifestId ?? $id),
            'created_at_utc='.$createdAt,
            'application_revision=abcdef1234567890',
            'object_storage=not_configured',
            '',
        ]));
    }
}
