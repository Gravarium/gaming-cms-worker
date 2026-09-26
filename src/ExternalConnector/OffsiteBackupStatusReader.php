<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class OffsiteBackupStatusReader
{
    private const HEADER = "target_id\tbackup_id\tchecked_at_utc\tresult\tattempts\trequired";
    private const MAX_STATUS_FILE_PATH_BYTES = 4096;
    private const MAX_STATUS_FILE_BYTES = 1_048_576;
    private const MAX_STATUS_LINE_BYTES = 256;
    private const MAX_STATUS_RECORDS = 1000;

    public function __construct(
        private string $statusFile = '/var/lib/gaming-cms-backup/offsite-status.tsv',
    ) {
    }

    /** @return array<string, OffsiteBackupTargetStatus> */
    public function read(): array
    {
        if (
            !$this->isSafeStatusFilePath($this->statusFile)
            || !is_file($this->statusFile)
            || is_link($this->statusFile)
            || !is_readable($this->statusFile)
        ) {
            return [];
        }

        $handle = fopen($this->statusFile, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $stat = fstat($handle);
            $size = $stat['size'] ?? null;
            if (!is_int($size) || $size < 0 || $size > self::MAX_STATUS_FILE_BYTES) {
                return [];
            }

            $header = $this->readBoundedLine($handle);
            if ($header !== self::HEADER) {
                return [];
            }

            $statuses = [];
            $recordCount = 0;
            while (($line = $this->readBoundedLine($handle)) !== false) {
                if ($line === null || ++$recordCount > self::MAX_STATUS_RECORDS) {
                    return [];
                }

                $fields = explode("\t", $line);
                if (count($fields) !== 6) {
                    return [];
                }

                [$targetKey, $backupId, $checkedAt, $result, $attempts, $required] = $fields;
                if (
                    preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $targetKey) !== 1
                    || preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $backupId) !== 1
                    || !in_array($result, ['success', 'failed'], true)
                    || preg_match('/^[1-9][0-9]{0,8}$/', $attempts) !== 1
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

    private function isSafeStatusFilePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_STATUS_FILE_PATH_BYTES
            && mb_check_encoding($path, 'UTF-8')
            && $path[0] === '/'
            && preg_match('/[\\x00-\\x1F\\x7F]/u', $path) === 0;
    }

    /** @param resource $handle */
    private function readBoundedLine($handle): string|false|null
    {
        $line = fgets($handle, self::MAX_STATUS_LINE_BYTES + 2);
        if ($line === false) {
            return false;
        }

        $content = rtrim($line, "\r\n");
        if (strlen($content) > self::MAX_STATUS_LINE_BYTES) {
            return null;
        }

        return $content;
    }
}
