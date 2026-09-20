<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class OffsiteBackupStatusReader
{
    public function __construct(
        private string $statusFile = '/var/lib/gaming-cms-backup/offsite-status.tsv',
    ) {
    }

    /** @return array<string, OffsiteBackupTargetStatus> */
    public function read(): array
    {
        if (!is_file($this->statusFile) || is_link($this->statusFile) || !is_readable($this->statusFile)) {
            return [];
        }

        $handle = fopen($this->statusFile, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            if (rtrim((string) fgets($handle), "\r\n") !== "target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired") {
                return [];
            }

            $statuses = [];
            while (($line = fgets($handle)) !== false) {
                $fields = explode("\t", rtrim($line, "\r\n"));
                if (count($fields) !== 6) {
                    return [];
                }

                [$targetKey, $backupId, $checkedAt, $result, $attempts, $required] = $fields;
                if (
                    preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $targetKey) !== 1
                    || preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $backupId) !== 1
                    || !in_array($result, ['success', 'failed'], true)
                    || preg_match('/^[1-9][0-9]*$/', $attempts) !== 1
                    || !in_array($required, ['0', '1'], true)
                    || isset($statuses[$targetKey])
                ) {
                    return [];
                }

                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $checkedAt, new \DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d\\TH:i:s\\Z') !== $checkedAt) {
                    return [];
                }

                $statuses[$targetKey] = new OffsiteBackupTargetStatus(
                    $targetKey,
                    $backupId,
                    $date,
                    $result === 'success',
                    (int) $attempts,
                    $required === '1',
                );
            }

            return $statuses;
        } finally {
            fclose($handle);
        }
    }
}
