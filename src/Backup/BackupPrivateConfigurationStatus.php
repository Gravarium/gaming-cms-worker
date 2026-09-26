<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupPrivateConfigurationStatus
{
    private const MAX_STATUS_FILE_PATH_BYTES = 4000;
    private const MAX_STATUS_FILE_BYTES = 1_048_576;
    private const MAX_STATUS_LINE_BYTES = 512;
    private const MAX_STATUS_RECORDS = 1000;

    public function __construct(private string $targetFile)
    {
    }

    /** @return array<string, 'ready'> */
    public function read(): array
    {
        if (
            !$this->isSafeStatusFilePath($this->targetFile)
            || !is_file($this->targetFile)
            || is_link($this->targetFile)
            || !is_readable($this->targetFile)
        ) {
            return [];
        }

        $mode = @fileperms($this->targetFile);
        if ($mode === false || ($mode & 0022) !== 0) {
            return [];
        }

        $handle = @fopen($this->targetFile, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $stat = @fstat($handle);
            if ($stat === false) {
                return [];
            }
            $size = $stat['size'] ?? null;
            if (!is_int($size) || $size > self::MAX_STATUS_FILE_BYTES) {
                return [];
            }

            $ready = [];
            $seen = [];
            $recordCount = 0;
            while (($line = $this->readBoundedLine($handle)) !== false) {
                if (
                    $line === null
                    || !mb_check_encoding($line, 'UTF-8')
                    || preg_match('/[\x00-\x1F\x7F]/', $line) === 1
                ) {
                    return [];
                }

                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (++$recordCount > self::MAX_STATUS_RECORDS) {
                    return [];
                }

                $fields = explode('|', $line);
                if (count($fields) !== 6) {
                    return [];
                }

                [$reference, $enabled, $required, $repository, $passwordFile, $configFile] = array_map('trim', $fields);
                if (
                    preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$/', $reference) !== 1
                    || isset($seen[$reference])
                    || !in_array($enabled, ['0', '1'], true)
                    || !in_array($required, ['0', '1'], true)
                    || !$this->safeField($repository)
                    || !$this->safeField($passwordFile)
                    || !$this->safeField($configFile)
                ) {
                    return [];
                }
                $seen[$reference] = true;

                if ($enabled === '1' && $repository !== '' && $passwordFile !== '' && $configFile !== '') {
                    $ready[strtolower($reference)] = 'ready';
                }
            }

            return $ready;
        } finally {
            fclose($handle);
        }
    }

    private function isSafeStatusFilePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_STATUS_FILE_PATH_BYTES
            && mb_check_encoding($path, 'UTF-8')
            && str_starts_with($path, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $path) === 0;
    }

    private function safeField(string $value): bool
    {
        return $value === ''
            || (
                strlen($value) <= self::MAX_STATUS_LINE_BYTES
                && mb_check_encoding($value, 'UTF-8')
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            );
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
