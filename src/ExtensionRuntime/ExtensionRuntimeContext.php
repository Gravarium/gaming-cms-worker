<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

use App\ExtensionPackage\ExtensionManifest;

final readonly class ExtensionRuntimeContext
{
    public function __construct(public ExtensionManifest $manifest)
    {
        if (
            !in_array($manifest->type, ['module', 'theme'], true)
            || preg_match('/\A[a-z][a-z0-9-]{1,39}\z/D', $manifest->key) !== 1
        ) {
            throw new \DomainException('Extension runtime identity is invalid.');
        }
    }

    public function id(): string
    {
        return $this->manifest->type.':'.$this->manifest->key;
    }
}
