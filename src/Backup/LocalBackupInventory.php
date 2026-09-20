<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class LocalBackupInventory
{
    public function __construct(
        #[Autowire('%env(string:BACKUP_ROOT)%')]
        private string $backupRoot,
    ) {
    }

    /**
     * @return array{available: bool, backups: list<array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string}>}
     */
    public function read(): array
    {
        if (!str_starts_with($this->backupRoot, '/') || !is_dir($this->backupRoot) || is_link($this->backupRoot) || !is_readable($this->backupRoot)) {
            return ['available' => false, 'backups' => []];
        }

        $entries = scandir($this->backupRoot);
        if ($entries === false) {
            return ['available' => false, 'backups' => []];
        }

        $backups = [];
        foreach ($entries as $id) {
            if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $id) !== 1) {
                continue;
            }
            $directory = $this->backupRoot.'/'.$id;
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }

            $manifest = $this->manifest($directory.'/manifest.txt');
            if ($manifest === null || ($manifest['backup_id'] ?? '') !== $id) {
                continue;
            }

            $createdAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $manifest['created_at_utc'] ?? '', new \DateTimeZone('UTC'));
            $revision = $manifest['application_revision'] ?? '';
            $objectStorage = $manifest['object_storage'] ?? '';
            if ($createdAt === false || preg_match('/^[0-9a-fA-F]{7,64}$/', $revision) !== 1 || !in_array($objectStorage, ['not_configured', 'skipped_requires_external_backup'], true)) {
                continue;
            }

            $bytes = 0;
            foreach (['database.dump', 'uploads.tar.gz', 'manifest.txt', 'checksums.sha256'] as $file) {
                $path = $directory.'/'.$file;
                if (!is_file($path) || is_link($path)) {
                    continue 2;
                }
                $size = filesize($path);
                $bytes += $size === false ? 0 : $size;
            }

            $backups[] = [
                'id' => $id,
                'createdAt' => $createdAt,
                'revision' => strtolower($revision),
                'bytes' => $bytes,
                'objectStorage' => $objectStorage,
            ];
        }

        usort($backups, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return ['available' => true, 'backups' => $backups];
    }

    public function directory(string $backupId): string
    {
        if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $backupId) !== 1) {
            throw new \InvalidArgumentException('Invalid backup ID.');
        }

        return rtrim($this->backupRoot, '/').'/'.$backupId;
    }

    /** @return array<string, string>|null */
    private function manifest(string $path): ?array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        $values = [];
        foreach ($lines as $line) {
            if (!str_contains($line, '=')) {
                return null;
            }
            [$key, $value] = explode('=', $line, 2);
            if ($key === '' || isset($values[$key])) {
                return null;
            }
            $values[$key] = $value;
        }

        return ($values['format_version'] ?? '') === '1' ? $values : null;
    }
}
