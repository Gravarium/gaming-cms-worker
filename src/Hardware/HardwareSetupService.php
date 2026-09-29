<?php

declare(strict_types=1);

namespace App\Hardware;

use App\Entity\Hardware\HardwareCommunitySetup;
use App\Entity\Hardware\HardwareProduct;
use App\Entity\User;

final class HardwareSetupService
{
    /** @param list<HardwareProduct> $products */
    public function createPending(User $owner, array $products, string $notes): HardwareCommunitySetup
    {
        $ownerId = $owner->getId();
        if ($ownerId === null || !$owner->isActive()) {
            throw new \DomainException('An active account is required to submit a setup.');
        }
        if ($products === [] || count($products) > 8) { throw new \InvalidArgumentException('Choose between one and eight products.'); }
        if (trim($notes) === '' || mb_strlen($notes) > 5000) { throw new \InvalidArgumentException('Setup notes are required and must be at most 5,000 characters.'); }

        $ids = [];
        foreach ($products as $product) {
            $id = $product->getId();
            if ($id === null || !$product->isPublished()) { throw new \InvalidArgumentException('Every setup product must be published.'); }
            $ids[] = $id;
        }
        if (count($ids) !== count(array_unique($ids))) { throw new \InvalidArgumentException('A product may only appear once in a setup.'); }

        new CommunitySetup($ownerId, $ids, $notes, false);

        return (new HardwareCommunitySetup())->setOwner($owner)->setProductIds($ids)->setNotes($notes)->setModerated(false);
    }
}
