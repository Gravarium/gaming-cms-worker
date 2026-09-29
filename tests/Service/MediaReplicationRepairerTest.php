<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MediaReplicationTask;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\Service\MediaReplicationRepairer;
use App\Service\MediaStorageCleanupJournal;
use App\Service\MediaUrlPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MediaReplicationRepairerTest extends TestCase
{
    public function testRepairRejectsHydratedTraversalFilenameBeforeFilesystemAccess(): void
    {
        $root = sys_get_temp_dir().'/media-replication-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $task = (new MediaReplicationTask())
            ->setTargetKey('target')
            ->setProviderKey('provider')
            ->setObjectKey('module/object.bin');
        $property = new \ReflectionProperty(MediaReplicationTask::class, 'stagedFilename');
        $property->setValue($task, '../../outside.bin');

        try {
            $repairer = $this->repairer($root);

            self::assertFalse($repairer->repair($task));
            self::assertSame(1, $task->getAttempts());
        } finally {
            @rmdir($root.'/var/media-repair');
            @rmdir($root.'/var');
            @rmdir($root);
        }
    }

    private function repairer(string $root): MediaReplicationRepairer
    {
        $source = new class implements ExternalConnectorTargetSource {
            public function enabledFor(string $capability): array
            {
                return [];
            }
        };
        $registry = new ExternalConnectorRegistry($source);
        $adapters = new ExternalConnectorAdapterRegistry([]);
        $dispatcher = new ExternalMediaDispatcher(
            new ExternalConnectorExecutor($registry, $adapters),
            $registry,
            $adapters,
        );

        return new MediaReplicationRepairer(
            $dispatcher,
            $this->createMock(EntityManagerInterface::class),
            new MediaUrlPolicy(),
            new MediaStorageCleanupJournal($root),
            $root,
        );
    }
}
