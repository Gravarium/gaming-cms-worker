<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaObject
{
    private const MAX_OBJECT_KEY_LENGTH = 500;
    private const MAX_LOCATION_LENGTH = 2048;
    private const MAX_SIZE_BYTES = 1_000_000_000_000;

    public string $objectKey;
    public string $location;
    public ?int $size;

    public function __construct(
        string $objectKey,
        string $location,
        ?int $size = null,
    ) {
        $this->objectKey = $this->normalizeObjectKey($objectKey);
        $this->location = $this->normalizeLocation($location);
        if ($size !== null && ($size < 0 || $size > self::MAX_SIZE_BYTES)) {
            throw new \InvalidArgumentException('External media result size is invalid.');
        }
        $this->size = $size;
    }

    private function normalizeObjectKey(string $objectKey): string
    {
        if ($objectKey === ''
            || !$this->isSafeText($objectKey, self::MAX_OBJECT_KEY_LENGTH)
            || trim($objectKey) !== $objectKey
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
        ) {
            throw new \InvalidArgumentException('External media result object key is invalid.');
        }

        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('External media result object key is invalid.');
            }
        }

        return $objectKey;
    }

    private function normalizeLocation(string $location): string
    {
        if (!$this->isSafeText($location, self::MAX_LOCATION_LENGTH) || str_contains($location, '\\')) {
            throw new \InvalidArgumentException('External media result location is invalid.');
        }

        $location = trim($location);
        if ($location === '') {
            throw new \InvalidArgumentException('External media result location is invalid.');
        }

        return $location;
    }

    private function isSafeText(string $value, int $maxLength): bool
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $maxLength) {
            return false;
        }

        return preg_match('/[\x00-\x1F\x7F]/u', $value) === 0;
    }
}
