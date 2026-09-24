<?php

declare(strict_types=1);

namespace App\Hardware;

final readonly class CommunitySetup
{
    /** @param list<int> $productIds */
    public function __construct(public int $ownerId, public array $productIds, public string $notes, public bool $moderated)
    {
        if ($ownerId < 1 || $productIds === [] || count($productIds) > 100 || count($productIds) !== count(array_unique($productIds))) {
            throw new \InvalidArgumentException('Setup owner and unique bounded products are required.');
        }
        foreach ($productIds as $id) {
            if ($id < 1) throw new \InvalidArgumentException('Invalid setup product.');
        }
        if (mb_strlen($notes) > 5000) throw new \InvalidArgumentException('Setup notes exceed bounds.');
    }

    public function isPublic(): bool { return $this->moderated; }
}
