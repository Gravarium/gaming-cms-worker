<?php

declare(strict_types=1);

namespace App\Theme\Module;

final readonly class ModuleThemeComposition
{
    /**
     * @param list<array{label:string,href:string}> $navigation
     * @param list<string> $contentWidgets
     * @param list<string> $sidebarWidgets
     */
    public function __construct(
        public string $theme,
        public string $template,
        public string $structure,
        public array $navigation,
        public array $contentWidgets,
        public array $sidebarWidgets,
    ) {
        if (!str_starts_with($this->template, 'themes/modules/') || str_contains($this->template, '..')) {
            throw new \InvalidArgumentException('Module theme template must stay in the module theme namespace.');
        }
        if (!in_array($this->structure, ['editorial', 'split', 'catalogue'], true)) {
            throw new \InvalidArgumentException('Unknown module theme structure.');
        }
    }
}
