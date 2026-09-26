<?php

declare(strict_types=1);

namespace App\Profile;

use App\Entity\MediaAsset;
use App\Repository\MediaAssetRepository;
use App\Service\MediaUrlPolicy;

final readonly class ProfileMediaReferencePolicy
{
    public function __construct(
        private MediaAssetRepository $assets,
        private MediaUrlPolicy $urls,
    ) {}

    public function resolve(?int $assetId): ?MediaAsset
    {
        if ($assetId === null) {
            return null;
        }
        if ($assetId < 1) {
            throw new \DomainException('Invalid profile media reference.');
        }

        $asset = $this->assets->find($assetId);
        if (!$asset instanceof MediaAsset
            || $asset->getModuleKey() !== 'users'
            || $asset->isDeletionPending()
            || !$asset->isImage()
            || !$this->urls->isSafePlayback($asset->getLocation())
        ) {
            throw new \DomainException('Profile media must be an active safe image owned by the users module.');
        }

        return $asset;
    }
}
