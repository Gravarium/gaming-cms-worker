<?php

declare(strict_types=1);

namespace App\Hardware;

use App\Entity\Hardware\HardwareBenchmarkMeasurement;
use App\Entity\Hardware\HardwareProduct;

final class HardwareComparisonBuilder
{
    /**
     * @param list<HardwareProduct> $products
     * @param list<HardwareBenchmarkMeasurement> $measurements
     * @return list<array{series:string,unit:string,methodology:string,version:string,procedure:string,testSystem:array<string,string>,disclosure:string,rows:list<array{product:string,value:float}>}>
     */
    public function build(array $products, array $measurements): array
    {
        $productNames = [];
        foreach ($products as $product) {
            if ($product->getId() !== null) { $productNames[$product->getId()] = $product->getName(); }
        }

        $groups = [];
        foreach ($measurements as $measurement) {
            $productId = $measurement->getProduct()->getId();
            if ($productId === null || !isset($productNames[$productId]) || $measurement->getSourceType() !== 'editorial') { continue; }
            $key = implode("\0", [$measurement->getMethodology()->getId() ?? 0, $measurement->getSeries(), $measurement->getUnit()]);
            if (!isset($groups[$key])) { $groups[$key] = ['measurement' => $measurement, 'values' => []]; }
            $current = $groups[$key]['values'][$productId] ?? null;
            if (!$current instanceof HardwareBenchmarkMeasurement || $measurement->getMeasuredAt() > $current->getMeasuredAt()) {
                $groups[$key]['values'][$productId] = $measurement;
            }
        }

        $result = [];
        foreach ($groups as $group) {
            /** @var HardwareBenchmarkMeasurement $representative */
            $representative = $group['measurement'];
            /** @var array<int, HardwareBenchmarkMeasurement> $values */
            $values = $group['values'];
            if (count($values) < 2) { continue; }

            $comparison = new ComparisonTable();
            foreach ($values as $measurement) { $comparison->add($measurement->toDomainValue()); }
            $rows = [];
            foreach ($comparison->accessibleRows() as $row) {
                $rows[] = ['product' => $productNames[$row['productId']], 'value' => $row['value']];
            }
            $methodology = $representative->getMethodology();
            $result[] = [
                'series' => $representative->getSeries(),
                'unit' => $representative->getUnit(),
                'methodology' => $methodology->getName(),
                'version' => $methodology->getVersion(),
                'procedure' => $methodology->getProcedure(),
                'testSystem' => $methodology->getTestSystem(),
                'disclosure' => $methodology->getDisclosure(),
                'rows' => $rows,
            ];
        }
        usort($result, static fn (array $left, array $right): int => [$left['series'], $left['methodology'], $left['version']] <=> [$right['series'], $right['methodology'], $right['version']]);

        return $result;
    }
}
