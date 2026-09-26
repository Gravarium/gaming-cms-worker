<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaStoreResult
{
    private const MAX_TARGETS = 100;
    private const MAX_OBJECT_KEY_LENGTH = 500;

    /**
     * On successful/degraded stores objectsByTarget contains objects that remain stored.
     * On a failed required-target store it contains only successful objects whose rollback also failed.
     * cleanupObjectKeysByTarget additionally records writes whose store result was uncertain and whose
     * compensating delete also failed. The caller must preserve those keys in its repair journal when
     * the complete media operation is aborted.
     *
     * @param array<string, ExternalMediaObject> $objectsByTarget
     * @param array<string, string> $cleanupObjectKeysByTarget
     */
    public function __construct(
        public ExternalConnectorExecutionSummary $summary,
        public array $objectsByTarget,
        public array $cleanupObjectKeysByTarget = [],
    ) {
        if (!self::validObjectMap($objectsByTarget)
            || !self::validCleanupMap($cleanupObjectKeysByTarget)
        ) {
            throw new \InvalidArgumentException('External media store result is invalid.');
        }
    }

    /** @param array<mixed> $objects */
    private static function validObjectMap(array $objects): bool
    {
        if (count($objects) > self::MAX_TARGETS) {
            return false;
        }

        foreach ($objects as $targetKey => $object) {
            if (!self::safeTargetKey($targetKey) || !$object instanceof ExternalMediaObject) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $cleanupKeys */
    private static function validCleanupMap(array $cleanupKeys): bool
    {
        if (count($cleanupKeys) > self::MAX_TARGETS) {
            return false;
        }

        foreach ($cleanupKeys as $targetKey => $objectKey) {
            if (!self::safeTargetKey($targetKey)
                || !is_string($objectKey)
                || !self::safeObjectKey($objectKey)
            ) {
                return false;
            }
        }

        return true;
    }

    private static function safeTargetKey(int|string $targetKey): bool
    {
        $targetKey = (string) $targetKey;

        return preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/D', $targetKey) === 1;
    }

    private static function safeObjectKey(string $objectKey): bool
    {
        if ($objectKey === ''
            || strlen($objectKey) > self::MAX_OBJECT_KEY_LENGTH
            || $objectKey !== trim($objectKey)
            || str_starts_with($objectKey, '/')
            || str_contains($objectKey, '\\')
            || preg_match('//u', $objectKey) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $objectKey) === 1
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
