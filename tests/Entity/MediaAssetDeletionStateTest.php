<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MediaAsset;
use PHPUnit\Framework\TestCase;

final class MediaAssetDeletionStateTest extends TestCase
{
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
