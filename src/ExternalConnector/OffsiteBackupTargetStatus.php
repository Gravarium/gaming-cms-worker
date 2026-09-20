<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class OffsiteBackupTargetStatus
{
    public function __construct(
        public string $targetKey,
        public string $backupId,
        public \DateTimeImmutable $checkedAt,
        public bool $successful,
        public int $attempts,
        public bool $required,
    ) {
    }
}
