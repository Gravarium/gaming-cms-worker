<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final readonly class KnowledgeRecord
{
    /** @param array<string, scalar|null> $attributes */
    public function __construct(
        public int $gameId,
        public string $type,
        public string $key,
        public int $version,
        public array $attributes,
        public string $source,
        public string $license,
    ) {
        if ($gameId < 1 || !in_array($type, ['class', 'item', 'quest', 'npc', 'boss', 'zone'], true)) {
            throw new \InvalidArgumentException('Invalid game or knowledge record type.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $key) || $version < 1 || count($attributes) > 100) {
            throw new \InvalidArgumentException('Invalid knowledge identity, version, or attributes.');
        }
        if (trim($source) === '' || trim($license) === '' || mb_strlen($source) > 500 || mb_strlen($license) > 160) {
            throw new \InvalidArgumentException('Source and licence provenance are required.');
        }
    }
}
