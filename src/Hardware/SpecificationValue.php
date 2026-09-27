<?php

declare(strict_types=1);

namespace App\Hardware;

final readonly class SpecificationValue
{
    public function __construct(public string $key, public float|int|string|bool $value, public string $unit = '')
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $key) || mb_strlen($unit) > 32) {
            throw new \InvalidArgumentException('Invalid specification key or unit.');
        }
        if (is_string($value) && ($value === '' || mb_strlen($value) > 500)) {
            throw new \InvalidArgumentException('Specification text must be non-empty and bounded.');
        }
    }
}
