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
        if (preg_match('~^widgets/modules/[a-z0-9_-]+\.html\.twig$~D', $this->template) !== 1) {
            throw new \InvalidArgumentException('Module widget template must stay in the module widget namespace.');
        }
        if (!in_array($this->visibility, ['public', 'authenticated', 'guild', 'moderator'], true)) {
            throw new \InvalidArgumentException('Unknown module widget visibility.');
        }
        if ($this->regions === [] || count($this->regions) > 2) {
            throw new \InvalidArgumentException('Module widget regions must be a non-empty list.');
        }
        foreach ($this->regions as $region) {
            if (!in_array($region, ['content', 'sidebar'], true)) {
                throw new \InvalidArgumentException('Module widget regions must be content or sidebar.');
            }
        }
        if (count($this->regions) !== count(array_unique($this->regions))) {
            throw new \InvalidArgumentException('Module widget regions must be unique.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $this->variant) !== 1) {
            throw new \InvalidArgumentException('Invalid module widget variant.');
        }
    }
}
