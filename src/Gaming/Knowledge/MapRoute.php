<?php

declare(strict_types=1);

namespace App\Gaming\Knowledge;

final readonly class MapRoute
{
    /** @param list<array{x: float, y: float}> $points */
    public function __construct(public int $gameId, public string $key, public array $points)
    {
        if ($gameId < 1 || !preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $key) || count($points) < 2 || count($points) > 500) {
            throw new \InvalidArgumentException('Invalid route identity or size.');
        }
        foreach ($points as $point) {
            if (!is_finite($point['x']) || !is_finite($point['y']) || $point['x'] < 0 || $point['x'] > 100 || $point['y'] < 0 || $point['y'] > 100) {
                throw new \InvalidArgumentException('Invalid route point.');
            }
        }
    }
}
