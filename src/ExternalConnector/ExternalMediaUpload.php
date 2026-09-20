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
        if (trim($objectKey) === '' || str_contains($objectKey, '..') || !str_starts_with($localPath, '/')) {
            throw new \InvalidArgumentException('External media requires a safe object key and absolute local path.');
        }
    }
}
