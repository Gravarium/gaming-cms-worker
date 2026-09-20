<?php

declare(strict_types=1);

namespace App\ExtensionPackage;

final readonly class ExtensionManifest
{
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
    }
}
