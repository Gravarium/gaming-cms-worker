<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupPrivateConfigurationStatus
{
    public function __construct(private string $targetFile)
    {
    }

    /** @return array<string, 'ready'> */
    public function read(): array
    {
        if (!str_starts_with($this->targetFile, '/') || !is_file($this->targetFile) || is_link($this->targetFile) || !is_readable($this->targetFile)) {
            return [];
        }

        $mode = @fileperms($this->targetFile);
        $lines = @file($this->targetFile, FILE_IGNORE_NEW_LINES);
        if ($mode === false || ($mode & 0022) !== 0 || $lines === false) {
            return [];
        }

        $ready = [];
        $seen = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
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
            ) {
                return [];
            }
            $seen[$reference] = true;

            if ($enabled === '1' && $repository !== '' && $passwordFile !== '' && $configFile !== '') {
                $ready[strtolower($reference)] = 'ready';
            }
        }

        return $ready;
    }
}
