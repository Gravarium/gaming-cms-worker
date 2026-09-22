<?php

declare(strict_types=1);

namespace App\Theme;

final readonly class ThemeDefinition
{
    /** @param array<string, string> $variables
     * @param list<string> $regions
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $version,
        public string $cmsConstraint,
        public array $variables,
        public array $regions = ['top','header','hero','below-hero','left-sidebar','main','right-sidebar','content-wide-1','content-wide-2','bottom','footer'],
        public string $fallbackRegion = 'main',
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]{1,39}$/', $key)) {
            throw new \InvalidArgumentException('Invalid theme key.');
        }
    }
}
