<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class BackupTargetSelectionExporter
{
    private const MAX_TARGET_KEY_BYTES = 64;
    private const MAX_CONFIGURATION_REFERENCE_BYTES = 120;

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
            if (
                !$this->isSafeToken($target->configurationReference, self::MAX_CONFIGURATION_REFERENCE_BYTES)
                || !$this->isSafeToken($target->targetKey, self::MAX_TARGET_KEY_BYTES)
            ) {
                throw new \RuntimeException('Backup target selection contains invalid data.');
            }

            $lines[] = sprintf(
                '%s|%s|%d',
                $target->configurationReference,
                $target->targetKey,
                $target->required ? 1 : 0,
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function isSafeToken(string $value, int $maxBytes): bool
    {
        return $value !== ''
            && strlen($value) <= $maxBytes
            && preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/', $value) === 1;
    }
}
