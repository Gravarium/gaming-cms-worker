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
        /** @var array<string, string> $uncertainCleanupKeys */
        $uncertainCleanupKeys = [];
        /** @var array<string, array{0: ExternalMediaConnectorAdapter, 1: ExternalConnectorTargetDefinition}> $successfulTargets */
        $successfulTargets = [];

        $summary = $this->executor->execute(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use ($upload, &$objects, &$successfulTargets, &$uncertainCleanupKeys): void {
                if (!$adapter instanceof ExternalMediaConnectorAdapter) {
                    throw new \LogicException('The selected adapter does not implement the media contract.');
                }

                try {
                    $object = $adapter->store($target, $upload);
                    if ($object->objectKey !== $upload->objectKey) {
                        throw new \DomainException('The media provider returned an unexpected object key.');
                    }
                } catch (\Throwable $exception) {
                    // A timeout/fault may happen after a provider committed the deterministic object key.
                    // Try an idempotent compensation immediately. If that cannot be confirmed, surface the
                    // key so the caller can keep it in the provider-neutral cleanup journal on abort.
                    try {
                        $adapter->delete($target, $upload->objectKey);
                    } catch (\Throwable) {
                        $uncertainCleanupKeys[$target->targetKey] = $upload->objectKey;
                    }

                    throw $exception;
                }

                $objects[$target->targetKey] = $object;
                $successfulTargets[$target->targetKey] = [$adapter, $target];
            },
        );

        if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_FAILED) {
            foreach (array_reverse($successfulTargets) as $targetKey => [$adapter, $target]) {
                try {
                    $adapter->delete($target, $objects[$targetKey]->objectKey);
                    unset($objects[$targetKey]);
                } catch (\Throwable) {
                    $uncertainCleanupKeys[$targetKey] = $objects[$targetKey]->objectKey;
                    // Keep the object in objectsByTarget as additional diagnostics for the caller.
                }
            }
        }

        return new ExternalMediaStoreResult($summary, $objects, $uncertainCleanupKeys);
    }

    public function storeForTarget(string $targetKey, ExternalMediaUpload $upload): ExternalMediaObject
    {
        $target = $this->targets->target(ExternalConnectorTarget::CAPABILITY_MEDIA, $targetKey);
        $adapter = $this->adapters->forTarget($target);
        if (!$adapter instanceof ExternalMediaConnectorAdapter) {
            throw new \LogicException('The selected adapter does not implement the media contract.');
        }

        try {
            $object = $adapter->store($target, $upload);
            if ($object->objectKey !== $upload->objectKey) {
                throw new \DomainException('The media provider returned an unexpected object key.');
            }

            return $object;
        } catch (\Throwable $exception) {
            // Repair uses the same deterministic key. A best-effort delete keeps a timed-out write from
            // becoming an untracked duplicate before the task is retried.
            try {
                $adapter->delete($target, $upload->objectKey);
            } catch (\Throwable) {
                // The persisted replication task still owns this key and will retry it idempotently.
            }

            throw $exception;
        }
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
