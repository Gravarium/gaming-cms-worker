<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalConnectorHealthStatus;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorHealthStatusBoundaryTest extends TestCase
{
    public function testIdentifiersAcceptTheirExactAsciiAndFourByteUtf8Boundaries(): void
    {
        $status = new ExternalConnectorHealthStatus();

        $capability = str_repeat('C', 40);
        self::assertSame($capability, $status->setCapability($capability)->getCapability());
        $multibyteCapability = str_repeat('🎮', 40);
        self::assertSame(40, mb_strlen($multibyteCapability, 'UTF-8'));
        self::assertSame(160, strlen($multibyteCapability));
        self::assertSame($multibyteCapability, $status->setCapability($multibyteCapability)->getCapability());

        $targetKey = str_repeat('T', 64);
        self::assertSame(str_repeat('t', 64), $status->setTargetKey($targetKey)->getTargetKey());
        $multibyteTargetKey = str_repeat('🎮', 64);
        self::assertSame(64, mb_strlen($multibyteTargetKey, 'UTF-8'));
        self::assertSame(256, strlen($multibyteTargetKey));
        self::assertSame($multibyteTargetKey, $status->setTargetKey($multibyteTargetKey)->getTargetKey());

        $providerKey = str_repeat('P', 64);
        self::assertSame(str_repeat('p', 64), $status->setProviderKey($providerKey)->getProviderKey());
        $multibyteProviderKey = str_repeat('🎮', 64);
        self::assertSame(64, mb_strlen($multibyteProviderKey, 'UTF-8'));
        self::assertSame(256, strlen($multibyteProviderKey));
        self::assertSame($multibyteProviderKey, $status->setProviderKey($multibyteProviderKey)->getProviderKey());
    }

    public function testIdentifierNormalizationRemainsTrimAndLowercaseWherePreviouslyApplied(): void
    {
        $status = new ExternalConnectorHealthStatus();

        $status->setCapability('  media  ');
        $status->setTargetKey('  PRIMARY ');
        $status->setProviderKey(' S3-Compatible  ');

        self::assertSame('media', $status->getCapability());
        self::assertSame('primary', $status->getTargetKey());
        self::assertSame('s3-compatible', $status->getProviderKey());

        $status->setCapability(' '.str_repeat('C', 40).' ');
        $status->setTargetKey(' '.str_repeat('T', 64).' ');
        $status->setProviderKey(' '.str_repeat('P', 64).' ');

        self::assertSame(str_repeat('C', 40), $status->getCapability());
        self::assertSame(str_repeat('t', 64), $status->getTargetKey());
        self::assertSame(str_repeat('p', 64), $status->getProviderKey());
    }

    public function testRejectedCapabilityPreservesItsPreviousValue(): void
    {
        $status = (new ExternalConnectorHealthStatus())->setCapability('media');

        foreach ([str_repeat('c', 41), str_repeat('🎮', 41), "\xFFmedia", "media\0value"] as $candidate) {
            $this->assertRejectedWithoutMutation(
                static function () use ($status, $candidate): void {
                    $status->setCapability($candidate);
                },
                static fn (): string => $status->getCapability(),
                'media',
            );
        }
    }

    public function testRejectedTargetKeyPreservesItsPreviousValue(): void
    {
        $status = (new ExternalConnectorHealthStatus())->setTargetKey('primary');

        foreach ([str_repeat('t', 65), str_repeat('🎮', 65), "\xFFtarget", "target\0key"] as $candidate) {
            $this->assertRejectedWithoutMutation(
                static function () use ($status, $candidate): void {
                    $status->setTargetKey($candidate);
                },
                static fn (): string => $status->getTargetKey(),
                'primary',
            );
        }
    }

    public function testRejectedProviderKeyPreservesItsPreviousValue(): void
    {
        $status = (new ExternalConnectorHealthStatus())->setProviderKey('s3-compatible');

        foreach ([str_repeat('p', 65), str_repeat('🎮', 65), "\xFFprovider", "provider\0key"] as $candidate) {
            $this->assertRejectedWithoutMutation(
                static function () use ($status, $candidate): void {
                    $status->setProviderKey($candidate);
                },
                static fn (): string => $status->getProviderKey(),
                's3-compatible',
            );
        }
    }

    /**
     * @param \Closure(): void $operation
     * @param \Closure(): string $read
     */
    private function assertRejectedWithoutMutation(\Closure $operation, \Closure $read, string $previousValue): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException) {
            self::assertSame($previousValue, $read());

            return;
        }

        self::fail('An invalid or out-of-bound connector health identifier must be rejected.');
    }
}
