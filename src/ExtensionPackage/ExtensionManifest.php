<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionManifest
{
    private const MAX_KEY_LENGTH = 40;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_VERSION_COMPONENT_LENGTH = 9;
    private const MAX_FILE_PATH_LENGTH = 240;
    private const MAX_FILE_COUNT = 1000;
    private const MAX_CAPABILITY_COUNT = 64;

    /** @param array<string, string> $files
     *  @param list<string> $capabilities
     */
    public function __construct(
        public string $type,
        public string $key,
        public string $name,
        public string $version,
        public string $cmsConstraint,
        public array $files,
        public array $capabilities,
    ) {
        if (
            !in_array($type, ['module', 'theme'], true)
            || !self::safeKey($key)
            || !self::safeName($name)
            || !self::safeVersion($version)
            || !self::safeConstraint($cmsConstraint)
            || !self::validFiles($files)
            || !self::validCapabilities($capabilities)
        ) {
            throw new \InvalidArgumentException('Extension manifest is invalid.');
        }
    }

    private static function safeKey(string $value): bool
    {
        return strlen($value) <= self::MAX_KEY_LENGTH
            && preg_match('/\A[a-z][a-z0-9-]{1,39}\z/D', $value) === 1;
    }

    private static function safeName(string $value): bool
    {
        return $value !== ''
            && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= self::MAX_NAME_LENGTH
            && $value === trim($value)
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private static function safeVersion(string $value): bool
    {
        return preg_match(
            '/\A[0-9]{1,'.self::MAX_VERSION_COMPONENT_LENGTH,'}\.[0-9]{1,'.self::MAX_VERSION_COMPONENT_LENGTH,'}\.[0-9]{1,'.self::MAX_VERSION_COMPONENT_LENGTH,'}\z/D',
            $value,
        ) === 1;
    }

    private static function safeConstraint(string $value): bool
    {
        return preg_match(
            '/\A\^[0-9]{1,'.self::MAX_VERSION_COMPONENT_LENGTH,'}\.[0-9]{1,'.self::MAX_VERSION_COMPONENT_LENGTH,'}\z/D',
            $value,
        ) === 1;
    }

    /** @param array<mixed> $files */
    private static function validFiles(array $files): bool
    {
        if (count($files) > self::MAX_FILE_COUNT) {
            return false;
        }

        foreach ($files as $path => $hash) {
            if (
                !is_string($path)
                || !self::safeRelativePath($path)
                || !is_string($hash)
                || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private static function safeRelativePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_FILE_PATH_LENGTH
            && !str_starts_with($path, '/')
            && !str_contains($path, '\\')
            && !str_contains($path, "\0")
            && preg_match('#(^|/)\.\.?(?:/|$)#', $path) !== 1
            && preg_match('#\A[A-Za-z0-9._/-]+\z#D', $path) === 1;
    }

    /** @param array<mixed> $capabilities */
    private static function validCapabilities(array $capabilities): bool
    {
        if (!array_is_list($capabilities) || count($capabilities) > self::MAX_CAPABILITY_COUNT) {
            return false;
        }

        $seen = [];
        foreach ($capabilities as $capability) {
            if (
                !is_string($capability)
                || !in_array($capability, ExtensionCapabilityPolicy::DECLARABLE, true)
                || isset($seen[$capability])
            ) {
                return false;
            }

            $seen[$capability] = true;
        }

        return true;
    }
}
