<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalMediaObject;
use App\ExternalConnector\ExternalMediaStoreResult;
use PHPUnit\Framework\TestCase;

final class ExternalMediaStoreResultTest extends TestCase
{
    public function testAcceptsTypedBoundedMapsAndPreservesRollbackEvidence(): void
    {
        $object = $this->object();
        $summary = $this->summary();
        $result = new ExternalMediaStoreResult(
            $summary,
            ['primary' => $object],
            ['optional' => 'media/file.png'],
        );

        self::assertSame($summary, $result->summary);
        self::assertSame(['primary' => $object], $result->objectsByTarget);
        self::assertSame(['optional' => 'media/file.png'], $result->cleanupObjectKeysByTarget);
    }

    public function testRejectsNonMediaObjectEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult(
            $this->summary(),
            ['primary' => new \stdClass()],
        );
    }

    public function testRejectsUnsafeTargetKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult(
            $this->summary(),
            ['../primary' => $this->object()],
        );
    }

    public function testRejectsOverlongTargetKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult(
            $this->summary(),
            [str_repeat('a', 65) => $this->object()],
        );
    }

    public function testRejectsUnsafeCleanupObjectKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult(
            $this->summary(),
            [],
            ['primary' => 'media/../file.png'],
        );
    }

    public function testRejectsMalformedUtf8CleanupObjectKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult(
            $this->summary(),
            [],
            ['primary' => "media/\xC3\x28.png"],
        );
    }

    public function testRejectsNonStringCleanupObjectKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult(
            $this->summary(),
            [],
            ['primary' => 42],
        );
    }

    public function testRejectsOversizedObjectMaps(): void
    {
        $objects = [];
        for ($i = 0; $i < 101; ++$i) {
            $objects['target-'.$i] = $this->object();
        }

        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult($this->summary(), $objects);
    }

    public function testRejectsOversizedCleanupMaps(): void
    {
        $cleanup = [];
        for ($i = 0; $i < 101; ++$i) {
            $cleanup['target-'.$i] = 'media/file-'.$i.'.png';
        }

        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaStoreResult($this->summary(), [], $cleanup);
    }

    private function summary(): ExternalConnectorExecutionSummary
    {
        return new ExternalConnectorExecutionSummary(ExternalConnectorTarget::CAPABILITY_MEDIA, []);
    }

    private function object(): ExternalMediaObject
    {
        return new ExternalMediaObject(
            'media/file.png',
            'https://cdn.example.invalid/media/file.png',
            42,
        );
    }
}
