<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MediaAssetRepository;

final readonly class MediaDeletionRepairer
{
    public function __construct(
        private MediaAssetRepository $assets,
        private MediaStorageManager $storage,
        private MediaAssetUsageResolver $usageResolver,
    ) {
    }

    /** @return array{repaired:int,failed:int} */
    public function repairPending(int $limit = 100): array
    {
        $repaired = 0;
        $failed = 0;

        foreach ($this->assets->pendingDeletion($limit) as $asset) {
            if ($this->usageResolver->isUsed($asset)) {
                ++$failed;
                continue;
            }

            try {
                $this->storage->delete($asset);
                ++$repaired;
            } catch (\Throwable) {
                ++$failed;
            }
        }

        return ['repaired' => $repaired, 'failed' => $failed];
    }
}
