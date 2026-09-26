<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionCapabilityGate
{
    private const MAX_CAPABILITY_LENGTH = 64;

    public function __construct(private ExtensionPermissionStore $permissions)
    {
    }

    public function require(ExtensionManifest $manifest, string $capability): void
    {
        if (
            strlen($capability) > self::MAX_CAPABILITY_LENGTH
            || !$this->isSafeText($capability)
            || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $capability) !== 1
            || !$this->permissions->allows($manifest, $capability)
        ) {
            throw new \DomainException('Extension capability is not approved.');
        }
    }

    private function isSafeText(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }
}
