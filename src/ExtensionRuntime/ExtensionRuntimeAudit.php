<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

final readonly class ExtensionRuntimeAudit
{
    public function __construct(private string $auditFile) {}

    public function record(string $extensionId, string $operation, string $status): void
    {
        if (!preg_match('/^[a-z]+:[a-z0-9-]+$/', $extensionId)
            || !preg_match('/^[a-z.]+$/', $operation)
            || !in_array($status, ['success', 'denied', 'failed'], true)
        ) {
            throw new \InvalidArgumentException('Invalid sanitized runtime audit event.');
        }

        $directory = dirname($this->auditFile);
        if (is_link($directory)) {
            throw new \RuntimeException('Runtime audit directory may not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Runtime audit directory unavailable.');
        }
        if (is_link($this->auditFile)) {
            throw new \RuntimeException('Runtime audit file may not be a symbolic link.');
        }

        $line = json_encode([
            'at' => gmdate('c'),
            'extension' => $extensionId,
            'operation' => $operation,
            'status' => $status,
        ], JSON_THROW_ON_ERROR)."\n";

        $handle = fopen($this->auditFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Runtime audit cannot be opened.');
        }
        try {
            $opened = fstat($handle);
            $path = @lstat($this->auditFile);
            if ($opened === false || $path === false
                || ($path['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $path['dev']
                || $opened['ino'] !== $path['ino']
                || !flock($handle, LOCK_EX)
            ) {
                throw new \RuntimeException('Runtime audit file is unsafe.');
            }
            if (fseek($handle, 0, SEEK_END) !== 0
                || fwrite($handle, $line) !== strlen($line)
                || !fflush($handle)
                || !chmod($this->auditFile, 0600)
            ) {
                throw new \RuntimeException('Runtime audit cannot be written.');
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
