<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionPermissionStore
{
    public function __construct(
        private string $permissionsFile,
        private ExtensionCapabilityPolicy $policy,
    ) {
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
        try {
            $value = json_decode((string) file_get_contents($this->permissionsFile), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Extension permission store is invalid.');
        }

        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @param array<string, mixed> $permissions */
    private function writeUnlocked(array $permissions): void
    {
        if (is_link($this->permissionsFile)) {
            throw new \DomainException('Extension permission store may not be a symbolic link.');
        }
        $temporary = $this->permissionsFile.'.tmp-'.bin2hex(random_bytes(6));
        $json = json_encode($permissions, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
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
            if (is_resource($handle)) { fclose($handle); }
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

    private function id(ExtensionManifest $manifest): string
    {
        return $manifest->type.':'.$manifest->key;
    }
}
