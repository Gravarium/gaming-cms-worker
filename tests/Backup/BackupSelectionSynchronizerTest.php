<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupSelectionSynchronizer;
use App\ExternalConnector\BackupTargetSelectionExporter;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use PHPUnit\Framework\TestCase;

final class BackupSelectionSynchronizerTest extends TestCase
{
    public function testRejectsSymlinkSelectionTarget(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }

        $projectDir = sys_get_temp_dir().'/backup-selection-link-'.bin2hex(random_bytes(6));
        mkdir($projectDir.'/var', 0700, true);
        $outside = $projectDir.'/outside.conf';
        file_put_contents($outside, 'protected');
        symlink($outside, $projectDir.'/var/backup-selection.conf');

        $source = new class implements ExternalConnectorTargetSource {
            public function enabledFor(string $capability): array { return []; }
        };
        $synchronizer = new BackupSelectionSynchronizer(
            new BackupTargetSelectionExporter(new ExternalConnectorRegistry($source)),
            $projectDir,
        );

        try {
            $this->expectException(\RuntimeException::class);
            $synchronizer->synchronize();
        } finally {
            @unlink($projectDir.'/var/backup-selection.conf');
            @unlink($outside);
            @rmdir($projectDir.'/var');
            @rmdir($projectDir);
        }
    }

    public function testWritesEmptySelectionAtomicallyWithPrivatePermissions(): void
    {
        $projectDir = sys_get_temp_dir().'/backup-selection-'.bin2hex(random_bytes(6));
        mkdir($projectDir.'/var', 0700, true);

        $source = new class implements ExternalConnectorTargetSource {
            public function enabledFor(string $capability): array
            {
                return [];
            }
        };
        $exporter = new BackupTargetSelectionExporter(new ExternalConnectorRegistry($source));

        $synchronizer = new BackupSelectionSynchronizer($exporter, $projectDir);
        $synchronizer->synchronize();

        self::assertSame(
            "# configuration_reference|target_key|required\n",
            file_get_contents($synchronizer->outputFile()),
        );
        self::assertSame(0600, fileperms($synchronizer->outputFile()) & 0777);

        unlink($synchronizer->outputFile());
        rmdir($projectDir.'/var');
        rmdir($projectDir);
    }
}
