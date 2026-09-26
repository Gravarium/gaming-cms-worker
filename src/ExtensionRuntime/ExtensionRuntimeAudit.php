<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

final readonly class ExtensionRuntimeAudit
{
    private const MAX_AUDIT_PATH_LENGTH = 4096;
    private const MAX_EXTENSION_ID_LENGTH = 128;
    private const MAX_OPERATION_LENGTH = 96;
    private const MAX_AUDIT_LINE_LENGTH = 1024;
    private const MAX_AUDIT_FILE_BYTES = 1048576;

    public function __construct(private string $auditFile)
    {
        if (
            $auditFile === ''
            || strlen($auditFile) > self::MAX_AUDIT_PATH_LENGTH
            || !$this->isSafeText($auditFile)
        ) {
            throw new \InvalidArgumentException('Invalid runtime audit path.');
        }
    }

    public function record(string $extensionId, string $operation, string $status): void
    {
        if (
            strlen($extensionId) > self::MAX_EXTENSION_ID_LENGTH
            || !$this->isSafeText($extensionId)
            || preg_match('/\A[a-z]+:[a-z0-9-]+\z/D', $extensionId) !== 1
            || strlen($operation) > self::MAX_OPERATION_LENGTH
            || !$this->isSafeText($operation)
            || preg_match('/\A[a-z.]+\z/D', $operation) !== 1
            || !$this->isSafeText($status)
            || !in_array($status, ['success', 'denied', 'failed'], true)
        ) {
            throw new \InvalidArgumentException('Invalid sanitized runtime audit event.');
        }

        $directory = dirname($this->auditFile);
        if (is_link($directory) || is_link($this->auditFile)) {
            throw new \DomainException('Extension runtime audit may not use symbolic links.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Runtime audit directory unavailable.');
        }

        $line = json_encode(
            ['at' => gmdate('c'), 'extension' => $extensionId, 'operation' => $operation, 'status' => $status],
            JSON_THROW_ON_ERROR,
        )."\n";
        if (strlen($line) > self::MAX_AUDIT_LINE_LENGTH) {
            throw new \RuntimeException('Runtime audit event is too large.');
        }

        $handle = fopen($this->auditFile, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Runtime audit cannot be opened.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Runtime audit cannot be locked.');
            }

            $stat = fstat($handle);
            if ($stat === false) {
                throw new \RuntimeException('Runtime audit size cannot be read.');
            }

            $size = $stat['size'];
            if ($size < 0 || $size > self::MAX_AUDIT_FILE_BYTES - strlen($line)) {
                throw new \RuntimeException('Runtime audit file is full.');
            }

            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new \RuntimeException('Runtime audit cannot seek.');
            }

            $offset = 0;
            $length = strlen($line);
            while ($offset < $length) {
                $written = fwrite($handle, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Runtime audit cannot be written.');
                }
                $offset += $written;
            }

            if (!fflush($handle) || !chmod($this->auditFile, 0600)) {
                throw new \RuntimeException('Runtime audit cannot be finalized.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isSafeText(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }
}
