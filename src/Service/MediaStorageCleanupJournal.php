<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MediaStorageCleanupJournal
{
    public const KIND_CONNECTOR = 'connector';
    public const KIND_LEGACY_S3 = 'legacy_s3';
    public const KIND_LOCAL = 'local';

    private const MAX_ENTRY_BYTES = 16 * 1024;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function recordConnector(string $targetKey, string $objectKey): void
    {
        $targetKey = strtolower(trim($targetKey));
        if (
            !mb_check_encoding($targetKey, 'UTF-8')
            || mb_strlen($targetKey) > 100
            || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $targetKey) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid media cleanup target.');
        }

        $this->record(self::KIND_CONNECTOR, $targetKey, $this->safeObjectKey($objectKey));
    }

    public function recordLegacyS3(string $objectKey): void
    {
        $this->record(self::KIND_LEGACY_S3, null, $this->safeObjectKey($objectKey));
    }

    public function recordLocal(string $location): void
    {
        $this->record(self::KIND_LOCAL, null, $this->safeLocalLocation($location));
    }

    /** @return list<array{id:string,kind:string,targetKey:?string,value:string}> */
    public function pending(int $limit = 100): array
    {
        $directory = $this->directory();
        $this->assertNotSymlink(dirname($directory), $directory);
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'/cleanup-*.json') ?: [];
        sort($files, SORT_STRING);
        $entries = [];

        foreach (array_slice($files, 0, max(1, min(500, $limit))) as $file) {
            $id = substr(basename($file), strlen('cleanup-'), -strlen('.json'));
            if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1 || is_link($file) || !is_file($file)) {
                continue;
            }

            $fileSize = filesize($file);
            if ($fileSize === false || $fileSize > self::MAX_ENTRY_BYTES) {
                continue;
            }
            $payload = file_get_contents($file);
            if ($payload === false || strlen($payload) > self::MAX_ENTRY_BYTES) {
                continue;
            }

            try {
                $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }

            $kind = (string) ($decoded['kind'] ?? '');
            $targetKey = isset($decoded['targetKey']) && is_string($decoded['targetKey']) ? $decoded['targetKey'] : null;
            $value = (string) ($decoded['value'] ?? '');
            try {
                if ($kind === self::KIND_CONNECTOR) {
                    if (
                        $targetKey === null
                        || !mb_check_encoding($targetKey, 'UTF-8')
                        || mb_strlen($targetKey) > 100
                        || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $targetKey) !== 1
                    ) {
                        continue;
                    }
                    $value = $this->safeObjectKey($value);
                } elseif ($kind === self::KIND_LEGACY_S3) {
                    if ($targetKey !== null) {
                        continue;
                    }
                    $value = $this->safeObjectKey($value);
                } elseif ($kind === self::KIND_LOCAL) {
                    if ($targetKey !== null) {
                        continue;
                    }
                    $value = $this->safeLocalLocation($value);
                } else {
                    continue;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }

            $expectedId = hash('sha256', $kind."\0".($targetKey ?? '')."\0".$value);
            if (!hash_equals($expectedId, $id)) {
                continue;
            }

            $entries[] = ['id' => $id, 'kind' => $kind, 'targetKey' => $targetKey, 'value' => $value];
        }

        return $entries;
    }

    public function forget(string $id): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid media cleanup id.');
        }

        $directory = $this->directory();
        $this->assertNotSymlink(dirname($directory), $directory);
        $path = $directory.'/cleanup-'.$id.'.json';
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Der erledigte Medien-Cleanup konnte nicht aus dem Journal entfernt werden.');
        }
    }

    private function record(string $kind, ?string $targetKey, string $value): void
    {
        $directory = $this->directory();
        $parent = dirname($directory);
        $this->assertNotSymlink($parent, $directory);
        if (!is_dir($parent) && !mkdir($parent, 0700) && !is_dir($parent)) {
            throw new \RuntimeException('Das Medien-Cleanup-Journal konnte nicht angelegt werden.');
        }
        $this->assertNotSymlink($parent);
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new \RuntimeException('Das Medien-Cleanup-Journal konnte nicht angelegt werden.');
        }
        $this->assertNotSymlink($directory);

        $id = hash('sha256', $kind."\0".($targetKey ?? '')."\0".$value);
        $path = $directory.'/cleanup-'.$id.'.json';
        if (is_file($path)) {
            return;
        }

        $payload = json_encode(
            ['kind' => $kind, 'targetKey' => $targetKey, 'value' => $value],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Der Medien-Cleanup konnte nicht sicher vorgemerkt werden.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Der Medien-Cleanup konnte nicht atomar vorgemerkt werden.');
        }
        @chmod($path, 0600);
    }

    private function safeLocalLocation(string $location): string
    {
        $location = trim($location);
        if (
            !mb_check_encoding($location, 'UTF-8')
            || !str_starts_with($location, '/uploads/media/')
            || mb_strlen($location) > 500
            || str_contains($location, '..')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $location) !== 0
        ) {
            throw new \InvalidArgumentException('Invalid local media cleanup location.');
        }

        return $location;
    }

    private function safeObjectKey(string $objectKey): string
    {
        $objectKey = trim($objectKey);
        if (
            !mb_check_encoding($objectKey, 'UTF-8')
            || $objectKey === ''
            || mb_strlen($objectKey) > 500
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $objectKey) !== 0
        ) {
            throw new \InvalidArgumentException('Invalid media cleanup object key.');
        }

        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid media cleanup object key.');
            }
        }

        return $objectKey;
    }

    private function assertNotSymlink(string ...$paths): void
    {
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new \DomainException('Das Medien-Cleanup-Journal darf keine symbolischen Links verwenden.');
            }
        }
    }

    private function directory(): string
    {
        return $this->projectDir.'/var/media-repair';
    }
}
