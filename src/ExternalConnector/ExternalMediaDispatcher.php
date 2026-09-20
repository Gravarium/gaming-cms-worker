<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalMediaDispatcher
{
    public function __construct(
        private ExternalConnectorExecutor $executor,
        private ExternalConnectorRegistry $targets,
        private ExternalConnectorAdapterRegistry $adapters,
    ) {
    }

    public function store(ExternalMediaUpload $upload): ExternalMediaStoreResult
    {
        /** @var array<string, ExternalMediaObject> $objects */
        $objects = [];
        /** @var array<string, array{0: ExternalMediaConnectorAdapter, 1: ExternalConnectorTargetDefinition}> $successfulTargets */
        $successfulTargets = [];

        $summary = $this->executor->execute(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use ($upload, &$objects, &$successfulTargets): void {
                if (!$adapter instanceof ExternalMediaConnectorAdapter) {
                    throw new \LogicException('The selected adapter does not implement the media contract.');
                }

                $objects[$target->targetKey] = $adapter->store($target, $upload);
                $successfulTargets[$target->targetKey] = [$adapter, $target];
            },
        );

        if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_FAILED) {
            foreach (array_reverse($successfulTargets) as $targetKey => [$adapter, $target]) {
                try {
                    $adapter->delete($target, $objects[$targetKey]->objectKey);
                    unset($objects[$targetKey]);
                } catch (\Throwable) {
                    // Residual objects stay in the result so the caller can journal cleanup safely.
                }
            }
        }

        return new ExternalMediaStoreResult($summary, $objects);
    }

    public function storeForTarget(string $targetKey, ExternalMediaUpload $upload): ExternalMediaObject
    {
        $target = $this->targets->target(ExternalConnectorTarget::CAPABILITY_MEDIA, $targetKey);
        $adapter = $this->adapters->forTarget($target);
        if (!$adapter instanceof ExternalMediaConnectorAdapter) {
            throw new \LogicException('The selected adapter does not implement the media contract.');
        }

        return $adapter->store($target, $upload);
    }

    public function deleteForTarget(string $targetKey, string $objectKey): void
    {
        $target = $this->targets->target(ExternalConnectorTarget::CAPABILITY_MEDIA, $targetKey);
        $adapter = $this->adapters->forTarget($target);
        if (!$adapter instanceof ExternalMediaConnectorAdapter) {
            throw new \LogicException('The selected adapter does not implement the media contract.');
        }

        $adapter->delete($target, $objectKey);
    }

    /** @param array<string, string> $objectKeysByTarget */
    public function delete(array $objectKeysByTarget): ExternalConnectorExecutionSummary
    {
        return $this->executor->execute(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use ($objectKeysByTarget): void {
                if (!isset($objectKeysByTarget[$target->targetKey])) {
                    return;
                }

                if (!$adapter instanceof ExternalMediaConnectorAdapter) {
                    throw new \LogicException('The selected adapter does not implement the media contract.');
                }

                $adapter->delete($target, $objectKeysByTarget[$target->targetKey]);
            },
        );
    }
}
