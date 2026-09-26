<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetDefinition
{
    /**
     * @param list<string> $regions
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $module,
        public string $source,
        public string $template,
        public string $visibility = 'public',
        public string $variant = 'cards',
        public array $regions = ['content', 'sidebar'],
    ) {
        if (preg_match('/^[a-z][a-z0-9.-]{1,79}$/D', $this->key) !== 1) {
            throw new \InvalidArgumentException('Invalid module widget key.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $this->module) !== 1) {
            throw new \InvalidArgumentException('Invalid module widget module.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,59}$/D', $this->source) !== 1) {
            throw new \InvalidArgumentException('Invalid module widget source.');
        }
        if (!str_starts_with($this->template, 'widgets/modules/') || str_contains($this->template, '..')) {
            throw new \InvalidArgumentException('Module widget template must stay in the module widget namespace.');
        }
        if (!in_array($this->visibility, ['public', 'authenticated', 'guild', 'moderator'], true)) {
            throw new \InvalidArgumentException('Unknown module widget visibility.');
        }
        if ($this->regions === []) {
            throw new \InvalidArgumentException('Module widget needs at least one region.');
        }
    }
}
