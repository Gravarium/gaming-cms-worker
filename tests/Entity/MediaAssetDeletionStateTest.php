<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Guild;
use App\Entity\MediaAsset;
use App\Entity\Video;
use PHPUnit\Framework\TestCase;

final class MediaAssetDeletionStateTest extends TestCase
{
    public function testPendingAssetCannotGainNewStructuredReferences(): void
    {
        $asset = (new MediaAsset())->markDeletionPending();

        try {
            (new Guild())->setLogo($asset);
            self::fail('Pending media was accepted as a guild logo.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\DomainException::class);
        (new Video())->setMediaAsset($asset);
    }

    public function testDeletionIntentIsStableAcrossRetries(): void
    {
        $asset = new MediaAsset();

        self::assertFalse($asset->isDeletionPending());
        self::assertNull($asset->getDeletionRequestedAt());

        $asset->markDeletionPending();
        $requestedAt = $asset->getDeletionRequestedAt();

        self::assertTrue($asset->isDeletionPending());
        self::assertSame(MediaAsset::DELETION_PENDING, $asset->getDeletionState());
        self::assertInstanceOf(\DateTimeImmutable::class, $requestedAt);

        $asset->markDeletionPending();
        self::assertSame($requestedAt, $asset->getDeletionRequestedAt());
    }
}
