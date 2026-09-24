<?php

declare(strict_types=1);

namespace App\Hardware;

final class ComparisonTable
{
    /** @var list<BenchmarkMeasurement> */
    private array $measurements = [];

    public function add(BenchmarkMeasurement $measurement): void
    {
        if ($this->measurements !== []) {
            $first = $this->measurements[0];
            if ($first->series !== $measurement->series || $first->unit !== $measurement->unit) {
                throw new \DomainException('Only measurements from the same series and unit are comparable.');
            }
        }
        $this->measurements[] = $measurement;
    }

    /** @return list<array{productId: int, value: float, unit: string, sourceType: string}> */
    public function accessibleRows(): array
    {
        $rows = array_map(static fn (BenchmarkMeasurement $measurement): array => [
            'productId' => $measurement->productId,
            'value' => $measurement->value,
            'unit' => $measurement->unit,
            'sourceType' => $measurement->sourceType,
        ], $this->measurements);
        usort($rows, static fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        return $rows;
    }
}
