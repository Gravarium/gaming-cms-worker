<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class BackupTargetSelectionExporter
{
    public function __construct(private ExternalConnectorRegistry $targets)
    {
    }

    public function export(bool $allowEmpty = false): string
    {
        $targets = $this->targets->forCapability(ExternalConnectorTarget::CAPABILITY_BACKUP);

        if ($targets === [] && !$allowEmpty) {
            throw new \RuntimeException('No enabled backup targets are configured in the CMS.');
        }

        $lines = ['# configuration_reference|target_key|required'];
        foreach ($targets as $target) {
            $lines[] = sprintf(
                '%s|%s|%d',
                $target->configurationReference,
                $target->targetKey,
                $target->required ? 1 : 0,
            );
        }

        return implode("\n", $lines)."\n";
    }
}
