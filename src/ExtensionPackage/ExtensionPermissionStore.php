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

        $stored = $this->read();
        $id = $this->id($manifest);
        $current = is_array($stored[$id] ?? null) ? $stored[$id] : [];
        $current[] = $capability;
        $current = array_values(array_unique(array_filter($current, 'is_string')));
        sort($current);
        $stored[$id] = $current;
        $this->write($stored);
    }

    public function revoke(ExtensionManifest $manifest, string $capability): void
    {
        $stored = $this->read();
        $id = $this->id($manifest);
        $current = is_array($stored[$id] ?? null) ? $stored[$id] : [];
        $stored[$id] = array_values(array_filter(
            $current,
            static fn (mixed $value): bool => is_string($value) && $value !== $capability,
        ));
        $this->write($stored);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->permissionsFile)) {
            return [];
        }
        if (is_link($this->permissionsFile)) {
            throw new \DomainException('Extension permission store may not be a symbolic link.');
        }
        try {
            $value = json_decode((string) file_get_contents($this->permissionsFile), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('Extension permission store is invalid.');
        }
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @param array<string, mixed> $permissions */
    private function write(array $permissions): void
    {
        $directory = dirname($this->permissionsFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Extension permission directory cannot be created.');
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

    private function id(ExtensionManifest $manifest): string
    {
        return $manifest->type.':'.$manifest->key;
    }
}
