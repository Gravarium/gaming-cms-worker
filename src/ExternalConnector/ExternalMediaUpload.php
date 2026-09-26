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
        if (!$this->safeObjectKey($objectKey) || !$this->safeLocalPath($localPath)) {
            throw new \InvalidArgumentException('External media requires a safe object key and absolute local path.');
        }
    }

    private function safeObjectKey(string $objectKey): bool
    {
        if ($objectKey === ''
            || strlen($objectKey) > 500
            || $objectKey !== trim($objectKey)
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || preg_match('//u', $objectKey) !== 1
            || preg_match('/[\\x00-\\x1F\\x7F]/', $objectKey) === 1
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

    private function safeLocalPath(string $localPath): bool
    {
        return $localPath !== ''
            && strlen($localPath) <= 500
            && str_starts_with($localPath, '/')
            && preg_match('//u', $localPath) === 1
            && preg_match('/[\\x00-\\x1F\\x7F]/', $localPath) !== 1;
    }
}
