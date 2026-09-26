<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\LocalBackupInventory;
use App\Backup\LocalBackupVerifier;
use App\Repository\BackupVerificationStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class LocalBackupVerifierTest extends TestCase
{
    private string $root;
    private string $backupRoot;
    private string $projectDir;
    private string $verifierMarker;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/gaming-cms-backup-verifier-'.bin2hex(random_bytes(6));
        $this->backupRoot = $this->root.'/backups';
        $this->projectDir = $this->root.'/project';
        $this->verifierMarker = $this->projectDir.'/verifier-invoked';

        mkdir($this->backupRoot, 0700, true);
        mkdir($this->projectDir.'/bin', 0700, true);
        file_put_contents(
            $this->projectDir.'/bin/verify-backup',
            '#!/bin/sh'.PHP_EOL.'touch "$APP_DIR/verifier-invoked"'.PHP_EOL,
        );
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
            if ($entry->isLink() || !$entry->isDir()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($this->root);
    }

    public function testRejectsAValidButUnlistedBackupBeforeRunningVerifierOrPersistingStatus(): void
    {
        $verifier = $this->verifier($this->backupRoot);

        try {
            $verifier->verify('20260920T030000Z-deadbee');
            self::fail('A syntactically valid ID absent from the inventory must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse(is_file($this->verifierMarker));
    }

    public function testRejectsSymlinkedBackupBeforeRunningVerifierOrPersistingStatus(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }

        $id = '20260920T030000Z-deadbee';
        $outside = $this->root.'/outside';
        mkdir($outside, 0700);
        if (!symlink($outside, $this->backupRoot.'/'.$id)) {
            self::markTestSkipped('Symlinks are unavailable.');
        }

        $verifier = $this->verifier($this->backupRoot);

        try {
            $verifier->verify($id);
            self::fail('A symlinked backup must not be treated as an inventory entry.');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse(is_file($this->verifierMarker));
    }

    public function testRejectsUnavailableInventoryBeforeRunningVerifierOrPersistingStatus(): void
    {
        $verifier = $this->verifier($this->root.'/missing-backups');

        try {
            $verifier->verify('20260920T030000Z-deadbee');
            self::fail('An unavailable inventory must be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertFalse(is_file($this->verifierMarker));
    }

    private function verifier(string $backupRoot): LocalBackupVerifier
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $reflection = new \ReflectionClass(BackupVerificationStatusRepository::class);
        /** @var BackupVerificationStatusRepository $statuses */
        $statuses = $reflection->newInstanceWithoutConstructor();

        return new LocalBackupVerifier(
            new LocalBackupInventory($backupRoot),
            $statuses,
            $entityManager,
            $this->projectDir,
        );
    }
}
