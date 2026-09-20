<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionCapabilityGate
{
    public function __construct(private ExtensionPermissionStore $permissions)
    {
    }

    public function require(ExtensionManifest $manifest, string $capability): void
    {
        if (!$this->permissions->allows($manifest, $capability)) {
            throw new \DomainException('Extension capability is not approved.');
        }
    }
}
