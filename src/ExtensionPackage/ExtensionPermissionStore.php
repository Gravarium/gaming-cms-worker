<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionPermissionStore
{
    private const MAX_PERMISSION_FILE_PATH_BYTES = 4000;
    private const MAX_PERMISSION_FILE_BYTES = 262_144;
    private const MAX_EXTENSION_ENTRIES = 500;
    private const MAX_CAPABILITIES_PER_EXTENSION = 64;

    public function __construct(
        private string $permissionsFile,
        private ExtensionCapabilityPolicy $policy,
    ) {
        if (!self::safePermissionFilePath($permissionsFile)) {
            throw new \InvalidArgumentException('Extension permission store path is invalid.');
        }
    }

    /** @return list<string> */
    public function approved(ExtensionManifest $manifest): array
    {
        $stored = $this->read();
        $approved = $stored[$this->id($manifest)] ?? [];
        if (!is_array($approved)) {
            return [];
        }

        return array_values(array_intersect(
            $manifest->capabilities,
            array_values(array_filter($approved, 'is_string')),
            ExtensionCapabilityPolicy::DECLARABLE,
        ));
    }

    public function allows(ExtensionManifest $manifest, string $capability): bool
    {
        return $this->policy->isGrantable($capability)
            && in_array($capability, $manifest->capabilities, true)
            && in_array($capability, $this->approved($manifest), true);
    }

    public function grant(ExtensionManifest $manifest, string $capability): void
    {
        if (!$this->policy->isGrantable($capability) || !in_array($capability, $manifest->capabilities, true)) {
            throw new \DomainException('Capability was not requested by this signed package or is forbidden.');
        }

        $this->withExclusiveLock(function () use ($manifest, $capability): void {
            $stored = $this->readUnlocked();
            $id = $this->id($manifest);
            $current = is_array($stored[$id] ?? null) ? $stored[$id] : [];
            if (!in_array($capability, $current, true) && count($current) >= self::MAX_CAPABILITIES_PER_EXTENSION) {
                throw new \DomainException('Extension capability grant limit was exceeded.');
            }

            $current[] = $capability;
            $current = array_values(array_unique(array_filter($current, 'is_string')));
            sort($current);
            $stored[$id] = $current;
            $this->writeUnlocked($stored);
        });
    }

    public function revoke(ExtensionManifest $manifest, string $capability): void
    {
        $this->withExclusiveLock(function () use ($manifest, $capability): void {
            $stored = $this->readUnlocked();
            $id = $this->id($manifest);
            $current = is_array($stored[$id] ?? null) ? $stored[$id] : [];
            $stored[$id] = array_values(array_filter(
                $current,
                static fn (mixed $value): bool => is_string($value) && $value !== $capability,
            ));
            $this->writeUnlocked($stored);
        });
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return $this->withExclusiveLock(fn (): array => $this->readUnlocked());
    }

    /** @return array<string, mixed> */
    private function readUnlocked(): array
    {
        if (is_link($this->permissionsFile)) {
            throw new \DomainException('Extension permission store may not be a symbolic link.');
        }
        if (!is_file($this->permissionsFile)) {
            return [];
        }

        $size = @filesize($this->permissionsFile);
        if (!is_int($size) || $size > self::MAX_PERMISSION_FILE_BYTES) {
            throw new \DomainException('Extension permission store exceeds its safe size.');
        }

        $raw = @file_get_contents(
            $this->permissionsFile,
            false,
            null,
            0,
            self::MAX_PERMISSION_FILE_BYTES + 1,
        );
        if (!is_string($raw) || strlen($raw) > self::MAX_PERMISSION_FILE_BYTES) {
            throw new \DomainException('Extension permission store cannot be read safely.');
        }

        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Extension permission store is invalid.');
        }

        if (!is_array($value) || !self::validStore($value)) {
            return [];
        }

        /** @var array<string, mixed> $permissions */
        $permissions = [];
        foreach ($value as $id => $capabilities) {
            if (!is_string($id)) {
                return [];
            }
            $permissions[$id] = $capabilities;
        }

        return $permissions;
    }

    /** @param array<string, mixed> $permissions */
    private function writeUnlocked(array $permissions): void
    {
        if (!self::validStore($permissions)) {
            throw new \DomainException('Extension permission store contents are invalid.');
        }

        if (is_link($this->permissionsFile)) {
            throw new \DomainException('Extension permission store may not be a symbolic link.');
        }
        $temporary = $this->permissionsFile.'.tmp-'.bin2hex(random_bytes(6));
        $json = json_encode($permissions, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (strlen($json) > self::MAX_PERMISSION_FILE_BYTES) {
            throw new \DomainException('Extension permission store exceeds its safe size.');
        }
        if (file_put_contents($temporary, $json."\n", LOCK_EX) === false || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new \RuntimeException('Extension permissions cannot be written.');
        }
        if (!rename($temporary, $this->permissionsFile)) {
            @unlink($temporary);
            throw new \RuntimeException('Extension permissions cannot be activated atomically.');
        }
    }

    private function withExclusiveLock(callable $callback): mixed
    {
        $directory = dirname($this->permissionsFile);
        if (is_link($directory)) {
            throw new \DomainException('Extension permission directory may not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Extension permission directory cannot be created.');
        }

        $lockFile = $this->permissionsFile.'.lock';
        if (is_link($lockFile)) {
            throw new \DomainException('Extension permission lock may not be a symbolic link.');
        }
        $handle = fopen($lockFile, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Extension permission lock is unavailable.');
        }
        @chmod($lockFile, 0600);

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<mixed> $store */
    private static function validStore(array $store): bool
    {
        if (($store !== [] && array_is_list($store)) || count($store) > self::MAX_EXTENSION_ENTRIES) {
            return false;
        }

        foreach ($store as $id => $capabilities) {
            if (!is_string($id) || !self::safeExtensionId($id) || !is_array($capabilities)
                || !self::validCapabilityList($capabilities)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $capabilities */
    private static function validCapabilityList(array $capabilities): bool
    {
        if (!array_is_list($capabilities) || count($capabilities) > self::MAX_CAPABILITIES_PER_EXTENSION) {
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

    private static function safeExtensionId(string $id): bool
    {
        return preg_match('/\A(?:module|theme):[a-z][a-z0-9-]{1,39}\z/D', $id) === 1;
    }

    private static function safePermissionFilePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_PERMISSION_FILE_PATH_BYTES
            && mb_check_encoding($path, 'UTF-8')
            && str_starts_with($path, '/')
            && preg_match('/[\x00-\x1F\x7F]/', $path) === 0;
    }

    private function id(ExtensionManifest $manifest): string
    {
        return $manifest->type.':'.$manifest->key;
    }
}
