<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MediaAssetReplica;
use App\Entity\MediaReplicationTask;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\ExternalConnector\ExternalMediaObject;
use App\ExternalConnector\ExternalMediaUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MediaReplicationRepairer
{
    public function __construct(
        private ExternalMediaDispatcher $dispatcher,
        private EntityManagerInterface $entityManager,
        private MediaUrlPolicy $urlPolicy,
        private MediaStorageCleanupJournal $cleanupJournal,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function repair(MediaReplicationTask $task): bool
    {
        $task->markAttempted();
        $asset = $task->getAsset();
        try {
            $path = $this->stagedPath($task);
        } catch (\Throwable) {
            return false;
        }

        if ($asset === null || !is_file($path) || is_link($path)) {
            return false;
        }

        foreach ($asset->getReplicas() as $replica) {
            if ($replica->getTargetKey() === $task->getTargetKey()) {
                $this->entityManager->remove($task);

                return true;
            }
        }

        $object = null;
        try {
            $object = $this->dispatcher->storeForTarget(
                $task->getTargetKey(),
                new ExternalMediaUpload($task->getObjectKey(), $path, $asset->getMimeType()),
            );
            $this->urlPolicy->assertSafeRemote($object->location);

            $asset->addReplica(
                (new MediaAssetReplica())
                    ->setTargetKey($task->getTargetKey())
                    ->setProviderKey($task->getProviderKey())
                    ->setObjectKey($object->objectKey)
                    ->setLocation($object->location),
            );
        } catch (\Throwable) {
            if ($object instanceof ExternalMediaObject) {
                $this->rollbackStoredObject($task);
            }

            return false;
        }

        $this->entityManager->remove($task);

        return true;
    }

    public function finalize(MediaReplicationTask $task): void
    {
        $path = $this->stagedPath($task);
        if ((is_file($path) || is_link($path)) && !unlink($path)) {
            throw new \RuntimeException('Die erledigte Medien-Reparaturdatei konnte nicht entfernt werden.');
        }
    }

    private function rollbackStoredObject(MediaReplicationTask $task): void
    {
        try {
            $this->dispatcher->deleteForTarget($task->getTargetKey(), $task->getObjectKey());
        } catch (\Throwable) {
            try {
                $this->cleanupJournal->recordConnector($task->getTargetKey(), $task->getObjectKey());
            } catch (\Throwable) {
                // The persisted replication task remains the authoritative repair intent.
            }
        }
    }

    private function stagedPath(MediaReplicationTask $task): string
    {
        $repairDirectory = $this->projectDir.'/var/media-repair';
        if (is_link($repairDirectory)) {
            throw new \DomainException('Das Medien-Reparaturverzeichnis darf kein symbolischer Link sein.');
        }

        return $repairDirectory.'/'.$task->getStagedFilename();
    }
}
