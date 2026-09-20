<?php

declare(strict_types=1);

namespace App\Theme;

final readonly class ThemeDefinition
{
    /** @param array<string, string> $variables */
    public function __construct(
        public string $key,
        public string $name,
        public string $version,
        public string $cmsConstraint,
        public array $variables,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]{1,39}$/', $key)) {
            throw new \InvalidArgumentException('Invalid theme key.');
        }
    }
}
