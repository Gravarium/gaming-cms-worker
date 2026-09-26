<?php

declare(strict_types=1);

namespace App\Gaming\Guide;

final readonly class BuildComponent
{
    /** @param list<string> $alternatives */
    public function __construct(
        public string $type,
        public string $key,
        public int $position,
        public array $alternatives = [],
    ) {
        if (!in_array($type, ['skill', 'gear', 'rotation', 'talent', 'consumable'], true)) {
            throw new \InvalidArgumentException('Unsupported build component type.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $key) || $position < 1 || $position > 200) {
            throw new \InvalidArgumentException('Invalid build component identity or position.');
        }
        if (count($alternatives) > 20) {
            throw new \InvalidArgumentException('Too many alternatives.');
        }
    }
}
