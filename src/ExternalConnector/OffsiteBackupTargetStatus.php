<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class OffsiteBackupTargetStatus
{
    private const MAX_TARGET_KEY_BYTES = 64;
    private const MAX_BACKUP_ID_BYTES = 28;
    private const MAX_ATTEMPTS = 999_999_999;

    public function __construct(
        public string $targetKey,
        public string $backupId,
        public \DateTimeImmutable $checkedAt,
        public bool $successful,
        public int $attempts,
        public bool $required,
    ) {
        if (
            strlen($targetKey) > self::MAX_TARGET_KEY_BYTES
            || !mb_check_encoding($targetKey, 'UTF-8')
            || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/u', $targetKey) !== 1
        ) {
            throw new \InvalidArgumentException('Offsite backup status contains an invalid target key.');
        }

        if (
            strlen($backupId) > self::MAX_BACKUP_ID_BYTES
            || !mb_check_encoding($backupId, 'UTF-8')
            || preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/u', $backupId) !== 1
        ) {
            throw new \InvalidArgumentException('Offsite backup status contains an invalid backup ID.');
        }

        if ($attempts < 1 || $attempts > self::MAX_ATTEMPTS) {
            throw new \InvalidArgumentException('Offsite backup status contains an invalid attempts value.');
        }
    }
}
