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
        ) { throw new \InvalidArgumentException('Invalid sanitized runtime audit event.'); }
        $directory = dirname($this->auditFile);
        if (is_link($directory) || is_link($this->auditFile)) { throw new \DomainException('Extension runtime audit may not use symbolic links.'); }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Runtime audit directory unavailable.'); }
        $line = json_encode(['at' => gmdate('c'), 'extension' => $extensionId, 'operation' => $operation, 'status' => $status], JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($this->auditFile, $line, FILE_APPEND | LOCK_EX) === false || !chmod($this->auditFile, 0600)) {
            throw new \RuntimeException('Runtime audit cannot be written.');
        }
    }
}
