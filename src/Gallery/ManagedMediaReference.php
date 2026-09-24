<?php

declare(strict_types=1);

namespace App\Gallery;

final readonly class ManagedMediaReference
{
    public function __construct(public int $assetId, public int $ownerId, public string $usageKey)
    {
        if ($assetId < 1 || $ownerId < 1 || !preg_match('/^gallery:[a-zA-Z0-9_.:-]{1,120}$/', $usageKey)) {
            throw new \InvalidArgumentException('Gallery media must use a tracked managed asset reference.');
        }
    }

    public static function fromExternalUrl(string $url): never
    {
        throw new \DomainException('Direct external media URLs bypassing MediaUrlPolicy are denied.');
    }
}
