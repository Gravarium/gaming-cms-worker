<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MediaAsset;
use App\Entity\MediaAssetReplica;
use PHPUnit\Framework\TestCase;

final class MediaAssetReplicaTest extends TestCase
{
    public function testAssetOwnsProviderNeutralReplicaMetadata(): void
    {
        $asset = new MediaAsset();
        $replica = (new MediaAssetReplica())
            ->setTargetKey(' S3-Primary ')
            ->setProviderKey(' S3-Compatible ')
            ->setObjectKey('media/file.png')
            ->setLocation(' https://cdn.example.invalid/media/file.png ');

        $asset->addReplica($replica);

        self::assertSame($asset, $replica->getAsset());
        self::assertSame('s3-primary', $replica->getTargetKey());
        self::assertSame('s3-compatible', $replica->getProviderKey());
        self::assertSame('media/file.png', $replica->getObjectKey());
        self::assertSame('https://cdn.example.invalid/media/file.png', $replica->getLocation());
        self::assertTrue($asset->getReplicas()->contains($replica));

        $asset->removeReplica($replica);
        self::assertFalse($asset->getReplicas()->contains($replica));
    }

    public function testRejectsTraversalAndAbsoluteObjectKeys(): void
    {
        foreach (['/media/file.png', '../file.png', 'media/../file.png', 'media\\file.png'] as $key) {
            try {
                (new MediaAssetReplica())->setObjectKey($key);
                self::fail('Unsafe replica key accepted: '.$key);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
