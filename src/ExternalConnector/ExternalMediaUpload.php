<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaUpload
{
    public function __construct(
        public string $objectKey,
        public string $localPath,
        public ?string $mimeType = null,
    ) {
        if (!$this->safeObjectKey($objectKey)
            || !str_starts_with($localPath, '/')
            || str_contains($localPath, "\0")
        ) {
            throw new \InvalidArgumentException('External media requires a safe object key and absolute local path.');
        }
    }

    private function safeObjectKey(string $objectKey): bool
    {
        if ($objectKey === ''
            || $objectKey !== trim($objectKey)
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $objectKey) === 1
        ) {
            return false;
        }

        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
