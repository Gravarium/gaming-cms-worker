<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class LocalBackupInventory
{
    private const MAX_BACKUP_ROOT_PATH_BYTES = 4096;
    private const MAX_BACKUP_ENTRIES = 1000;
    private const MAX_MANIFEST_BYTES = 16_384;
    private const MAX_MANIFEST_LINE_BYTES = 512;
    private const MAX_BACKUP_COMPONENT_BYTES = 4_294_967_296;
    private const MAX_BACKUP_TOTAL_BYTES = 17_179_869_184;

    /** @var list<string> */
    private const MANIFEST_KEYS = [
        'format_version',
        'backup_id',
        'created_at_utc',
        'application_revision',
        'object_storage',
    ];

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
        if (
            !$this->isSafeBackupRoot($this->backupRoot)
            || !is_dir($this->backupRoot)
            || is_link($this->backupRoot)
            || !is_readable($this->backupRoot)
        ) {
            return ['available' => false, 'backups' => []];
        }

        $entries = @scandir($this->backupRoot);
        if ($entries === false || count($entries) > self::MAX_BACKUP_ENTRIES) {
            return ['available' => false, 'backups' => []];
        }

        $backups = [];
        foreach ($entries as $id) {
            if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $id) !== 1) {
                continue;
            }

            $directory = rtrim($this->backupRoot, '/').'/'.$id;
            if (!is_dir($directory) || is_link($directory) || !is_readable($directory)) {
                continue;
            }

            $manifest = $this->manifest($directory.'/manifest.txt');
            if ($manifest === null || ($manifest['backup_id'] ?? '') !== $id) {
                continue;
            }

            $createdAt = \DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                $manifest['created_at_utc'] ?? '',
                new \DateTimeZone('UTC'),
            );
            $revision = $manifest['application_revision'] ?? '';
            $objectStorage = $manifest['object_storage'] ?? '';
            if (
                $createdAt === false
                || $createdAt->format('Y-m-d\TH:i:s\Z') !== ($manifest['created_at_utc'] ?? '')
                || preg_match('/^[0-9a-fA-F]{7,64}$/', $revision) !== 1
                || !in_array($objectStorage, ['not_configured', 'skipped_requires_external_backup'], true)
            ) {
                continue;
            }

            $bytes = 0;
            foreach (['database.dump', 'uploads.tar.gz', 'manifest.txt', 'checksums.sha256'] as $file) {
                $path = $directory.'/'.$file;
                if (!is_file($path) || is_link($path) || !is_readable($path)) {
                    continue 2;
                }

                $size = @filesize($path);
                if (
                    !is_int($size)
                    || $size < 0
                    || $size > self::MAX_BACKUP_COMPONENT_BYTES
                    || $bytes > self::MAX_BACKUP_TOTAL_BYTES - $size
                ) {
                    continue 2;
                }

                $bytes += $size;
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
        if (
            !$this->isSafeBackupRoot($this->backupRoot)
            || preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $backupId) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid backup directory.');
        }

        return rtrim($this->backupRoot, '/').'/'.$backupId;
    }

    /** @return array<string, string>|null */
    private function manifest(string $path): ?array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $stat = @fstat($handle);
            $size = $stat['size'] ?? null;
            if (!is_int($size) || $size < 0 || $size > self::MAX_MANIFEST_BYTES) {
                return null;
            }

            $contents = stream_get_contents($handle, self::MAX_MANIFEST_BYTES + 1);
            if (
                !is_string($contents)
                || strlen($contents) > self::MAX_MANIFEST_BYTES
                || !mb_check_encoding($contents, 'UTF-8')
            ) {
                return null;
            }

            $lines = preg_split("/\r\n|\n|\r/", $contents);
            if (!is_array($lines)) {
                return null;
            }

            /** @var array<string, string> $values */
            $values = [];
            foreach ($lines as $line) {
                if (!is_string($line)) {
                    return null;
                }
                if ($line === '') {
                    continue;
                }
                if (
                    strlen($line) > self::MAX_MANIFEST_LINE_BYTES
                    || preg_match('/[\x00-\x1F\x7F]/', $line) === 1
                ) {
                    return null;
                }

                $separator = strpos($line, '=');
                if ($separator === false) {
                    return null;
                }

                $key = substr($line, 0, $separator);
                $value = substr($line, $separator + 1);
                if (
                    $value === ''
                    || !in_array($key, self::MANIFEST_KEYS, true)
                    || isset($values[$key])
                ) {
                    return null;
                }

                $values[$key] = $value;
            }

            if (count($values) !== count(self::MANIFEST_KEYS) || ($values['format_version'] ?? '') !== '1') {
                return null;
            }

            return $values;
        } finally {
            fclose($handle);
        }
    }

    private function isSafeBackupRoot(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_BACKUP_ROOT_PATH_BYTES
            && mb_check_encoding($path, 'UTF-8')
            && str_starts_with($path, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $path) === 0;
    }
}
