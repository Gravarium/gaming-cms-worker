<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class BackupTargetOverview
{
    /**
     * @param iterable<ExternalConnectorTarget> $targets
     * @param array<string, OffsiteBackupTargetStatus> $statuses
     * @return array{enabled: int, required: int, optional: int, healthy: int, failed: int, missing: int, stale: int, latest: ?\DateTimeImmutable, overall: string}
     */
    public function summarize(iterable $targets, array $statuses, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = ['enabled' => 0, 'required' => 0, 'optional' => 0, 'healthy' => 0, 'failed' => 0, 'missing' => 0, 'stale' => 0, 'latest' => null, 'overall' => 'not_configured'];

        foreach ($targets as $target) {
            if ($target->getCapability() !== ExternalConnectorTarget::CAPABILITY_BACKUP || !$target->isEnabled()) {
                continue;
            }

            ++$result['enabled'];
            ++$result[$target->isRequired() ? 'required' : 'optional'];
            $targetKey = $target->getTargetKey();
            $status = $statuses[$targetKey] ?? null;
            if ($status === null || $status->targetKey !== $targetKey) {
                ++$result['missing'];
                continue;
            }

            if ($result['latest'] === null || $status->checkedAt > $result['latest']) {
                $result['latest'] = $status->checkedAt;
            }
            if ($status->checkedAt < $now->modify('-26 hours')) {
                ++$result['stale'];
            }
            ++$result[$status->successful ? 'healthy' : 'failed'];
        }

        if ($result['enabled'] > 0) {
            $result['overall'] = $result['failed'] > 0 || $result['missing'] > 0 || $result['stale'] > 0
                ? 'attention'
                : 'healthy';
        }

        return $result;
    }
}
