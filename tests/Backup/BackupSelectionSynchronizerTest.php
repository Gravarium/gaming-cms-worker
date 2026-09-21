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
        $synchronizer->markPending();
        self::assertTrue($synchronizer->hasPending());
        self::assertSame(0600, fileperms($synchronizer->pendingFile()) & 0777);

        $synchronizer->synchronize();

        self::assertFalse($synchronizer->hasPending());
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
