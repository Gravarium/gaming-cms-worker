<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\Repository\ExternalConnectorHealthStatusRepository;

final readonly class ConnectorHealthReport
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    public function __construct(
        private ExternalConnectorHealthStatusRepository $healthStatuses,
        private OffsiteBackupStatusReader $backupStatuses,
    ) {
    }

    /**
     * @param iterable<ExternalConnectorTarget> $targets
     * @return list<array{
     *     capability: string,
     *     targetKey: string,
     *     displayName: string,
     *     providerKey: string,
     *     enabled: bool,
     *     required: bool,
     *     priority: int,
     *     status: 'healthy'|'failed'|'pending',
     *     checkedAt: ?string
     * }>
     */
    public function rows(iterable $targets, ?string $capability = null, ?string $status = null): array
    {
        $healthByCapability = [];
        foreach (ExternalConnectorTarget::CAPABILITIES as $knownCapability) {
            if ($knownCapability === ExternalConnectorTarget::CAPABILITY_BACKUP) {
                continue;
            }

            $healthByCapability[$knownCapability] = $this->healthStatuses->forCapability($knownCapability);
        }

        $backupStatuses = $this->backupStatuses->read();
        $rows = [];
        foreach ($targets as $target) {
            if ($capability !== null && $target->getCapability() !== $capability) {
                continue;
            }

            $checkedAt = null;
            $targetStatus = self::STATUS_PENDING;
            if ($target->getCapability() === ExternalConnectorTarget::CAPABILITY_BACKUP) {
                $backupStatus = $backupStatuses[$target->getTargetKey()] ?? null;
                if ($backupStatus instanceof OffsiteBackupTargetStatus) {
                    $targetStatus = $backupStatus->successful ? self::STATUS_HEALTHY : self::STATUS_FAILED;
                    $checkedAt = $backupStatus->checkedAt->format(DATE_ATOM);
                }
            } else {
                $healthStatus = ($healthByCapability[$target->getCapability()] ?? [])[$target->getTargetKey()] ?? null;
                if ($healthStatus !== null) {
                    $targetStatus = $healthStatus->isSuccessful() ? self::STATUS_HEALTHY : self::STATUS_FAILED;
                    $checkedAt = $healthStatus->getCheckedAt()->format(DATE_ATOM);
                }
            }

            if ($status !== null && $targetStatus !== $status) {
                continue;
            }

            $rows[] = [
                'capability' => $target->getCapability(),
                'targetKey' => $target->getTargetKey(),
                'displayName' => $target->getDisplayName(),
                'providerKey' => $target->getProviderKey(),
                'enabled' => $target->isEnabled(),
                'required' => $target->isRequired(),
                'priority' => $target->getPriority(),
                'status' => $targetStatus,
                'checkedAt' => $checkedAt,
            ];
        }

        return $rows;
    }
}
