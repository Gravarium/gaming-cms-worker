<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final readonly class KnowledgeRelationship
{
    public function __construct(
        public int $gameId,
        public string $fromKey,
        public string $type,
        public string $toKey,
    ) {
        if ($gameId < 1 || !in_array($type, ['drops', 'starts', 'ends', 'located_in', 'requires', 'rewards', 'related'], true)) {
            throw new \InvalidArgumentException('Invalid relationship type.');
        }
        foreach ([$fromKey, $toKey] as $key) {
            if (!preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $key)) {
                throw new \InvalidArgumentException('Invalid relationship endpoint.');
            }
        }
        if ($fromKey === $toKey) {
            throw new \DomainException('Self-referential knowledge relationships are denied.');
        }
    }
}
