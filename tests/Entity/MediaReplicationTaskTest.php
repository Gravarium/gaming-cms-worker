<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MediaAsset;
use App\Entity\MediaReplicationTask;
use PHPUnit\Framework\TestCase;

final class MediaReplicationTaskTest extends TestCase
{
    public function testNormalizesPublicRoutingDataAndTracksAttempts(): void
    {
        $asset = new MediaAsset();
        $task = (new MediaReplicationTask())
            ->setAsset($asset)
            ->setTargetKey(' Archive.One ')
            ->setProviderKey(' S3-Compatible ')
            ->setObjectKey('news/example.jpg')
            ->setStagedFilename('0123456789abcdef0123456789abcdef.bin');

        self::assertSame('archive.one', $task->getTargetKey());
        self::assertSame('s3-compatible', $task->getProviderKey());
        self::assertSame('news/example.jpg', $task->getObjectKey());
        self::assertSame(0, $task->getAttempts());

        $task->markAttempted();
        self::assertSame(1, $task->getAttempts());
        self::assertNotNull($task->getLastAttemptAt());
    }

    public function testRejectsPathsAsStagedFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MediaReplicationTask())->setStagedFilename('../secret');
    }

    public function testRejectsUnsafeObjectKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MediaReplicationTask())->setObjectKey('media/../secret');
    }

    public function testRejectsMalformedUtf8ObjectKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MediaReplicationTask())->setObjectKey("media/\xC3\x28.bin");
    }
}
