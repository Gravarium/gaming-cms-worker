<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaObject
{
    public function __construct(
        public string $objectKey,
        public string $location,
        public ?int $size = null,
    ) {
        if ($objectKey === '' || $location === '' || ($size !== null && $size < 0)) {
            throw new \InvalidArgumentException('External media result is invalid.');
        }
    }
}
