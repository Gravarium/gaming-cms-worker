<?php

declare(strict_types=1);

namespace App\Hardware;

use App\Entity\Hardware\HardwareProduct;
use App\Entity\Hardware\HardwareSpecification;

final class HardwareSpecificationSet
{
    /** @return list<SpecificationValue> */
    public function decode(string $json): array
    {
        if (strlen($json) > 20_000) { throw new \InvalidArgumentException('Specifications exceed the allowed size.'); }
        try {
            $decoded = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Specifications must be valid JSON.', previous: $exception);
        }
        if (!$decoded instanceof \stdClass || count(get_object_vars($decoded)) > 50) {
            throw new \InvalidArgumentException('Specifications must be an object with at most 50 entries.');
        }

        $values = [];
        foreach (get_object_vars($decoded) as $key => $entry) {
            if (!$entry instanceof \stdClass) { throw new \InvalidArgumentException('Each specification needs a value and unit.'); }
            $fields = get_object_vars($entry);
            if (array_diff(array_keys($fields), ['value', 'unit']) !== [] || !array_key_exists('value', $fields)) {
                throw new \InvalidArgumentException('Each specification needs only value and optional unit fields.');
            }
            $value = $fields['value'];
            $unit = $fields['unit'] ?? '';
            if (!is_float($value) && !is_int($value) && !is_string($value) && !is_bool($value)) {
                throw new \InvalidArgumentException('Specification values must be scalar.');
            }
            if (!is_string($unit)) { throw new \InvalidArgumentException('Specification units must be text.'); }
            $values[] = new SpecificationValue($key, $value, $unit);
        }

        return $values;
    }

    /** @param list<SpecificationValue> $values */
    public function replace(HardwareProduct $product, array $values): void
    {
        $product->clearSpecifications();
        foreach ($values as $value) {
            $product->addSpecification((new HardwareSpecification())->setSpecKey($value->key)->setValue($value->value)->setUnit($value->unit));
        }
    }

    public function encode(HardwareProduct $product): string
    {
        $specifications = [];
        foreach ($product->getSpecifications() as $specification) {
            $specifications[$specification->getSpecKey()] = ['value' => $specification->getValue(), 'unit' => $specification->getUnit()];
        }

        return json_encode((object) $specifications, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
