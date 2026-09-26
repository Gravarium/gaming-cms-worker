<?php

declare(strict_types=1);

namespace App\Gaming\Guide;

final class TierList
{
    /** @var array<string, array{tier: string, reason: string}> */
    private array $entries = [];

    public function __construct(
        public readonly string $criteria,
        public readonly string $provenance,
    ) {
        if (trim($criteria) === '' || trim($provenance) === '' || mb_strlen($criteria) > 2000 || mb_strlen($provenance) > 1000) {
            throw new \InvalidArgumentException('Tier-list criteria and provenance are required and bounded.');
        }
    }

    public function rank(string $key, string $tier, string $reason): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $key) || !in_array($tier, ['S', 'A', 'B', 'C', 'D', 'F'], true) || trim($reason) === '') {
            throw new \InvalidArgumentException('Invalid tier-list entry.');
        }
        $this->entries[$key] = ['tier' => $tier, 'reason' => $reason];
    }

    /** @return array<string, array{tier: string, reason: string}> */
    public function entries(): array
    {
        return $this->entries;
    }
}
