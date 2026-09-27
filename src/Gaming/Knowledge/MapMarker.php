<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final readonly class MapMarker
{
    public function __construct(
        public int $gameId,
        public string $layer,
        public string $key,
        public float $x,
        public float $y,
        public string $visibility = 'public',
    ) {
        if ($gameId < 1 || !preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $layer) || !preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $key)) {
            throw new \InvalidArgumentException('Invalid marker identity.');
        }
        if (!is_finite($x) || !is_finite($y) || $x < 0 || $x > 100 || $y < 0 || $y > 100) {
            throw new \InvalidArgumentException('Marker coordinates must be normalized percentages.');
        }
        if (!in_array($visibility, ['public', 'members', 'private'], true)) {
            throw new \InvalidArgumentException('Unknown marker visibility.');
        }
    }
}
