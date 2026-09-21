<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MediaAssetReplica;
use App\Entity\MediaReplicationTask;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\ExternalConnector\ExternalMediaUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MediaReplicationRepairer
{
    public function __construct(
        private ExternalMediaDispatcher $dispatcher,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function repair(MediaReplicationTask $task): bool
    {
        $task->markAttempted();
        $asset = $task->getAsset();
        $path = $this->stagedPath($task);

        if ($asset === null || !is_file($path) || is_link($path)) {
            return false;
        }

        foreach ($asset->getReplicas() as $replica) {
            if ($replica->getTargetKey() === $task->getTargetKey()) {
                $this->entityManager->remove($task);

                return true;
            }
        }

        try {
            $object = $this->dispatcher->storeForTarget(
                $task->getTargetKey(),
                new ExternalMediaUpload($task->getObjectKey(), $path, $asset->getMimeType()),
            );
        } catch (\Throwable) {
            return false;
        }

        $asset->addReplica(
            (new MediaAssetReplica())
                ->setTargetKey($task->getTargetKey())
                ->setProviderKey($task->getProviderKey())
                ->setObjectKey($object->objectKey)
                ->setLocation($object->location),
        );
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

    private function stagedPath(MediaReplicationTask $task): string
    {
        return $this->projectDir.'/var/media-repair/'.$task->getStagedFilename();
    }
}
