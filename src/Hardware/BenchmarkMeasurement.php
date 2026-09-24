<?php

declare(strict_types=1);

namespace App\Hardware;

final readonly class BenchmarkMeasurement
{
    public function __construct(
        public int $productId,
        public string $series,
        public float $value,
        public string $unit,
        public int $sampleCount,
        public string $sourceType = 'editorial',
    ) {
        if ($productId < 1 || trim($series) === '' || !is_finite($value) || trim($unit) === '' || $sampleCount < 1 || $sampleCount > 1_000_000) {
            throw new \InvalidArgumentException('Invalid benchmark measurement.');
        }
        if (!in_array($sourceType, ['editorial', 'community', 'manufacturer', 'advertising'], true)) {
            throw new \InvalidArgumentException('Unknown measurement source.');
        }
    }
}
