<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CmsModuleState;
use PHPUnit\Framework\TestCase;

final class CmsModuleStateBoundaryTest extends TestCase
{
    public function testModuleKeyPreservesTrimAndLowercaseAtTheColumnBoundary(): void
    {
        $key = str_repeat('M', 64);
        $state = (new CmsModuleState())->setModuleKey(' '.$key.' ');

        self::assertSame(str_repeat('m', 64), $state->getModuleKey());

        $multibyteKey = str_repeat('🎮', 64);
        self::assertSame($multibyteKey, (new CmsModuleState())->setModuleKey($multibyteKey)->getModuleKey());
    }

    public function testRejectsOversizedAndMalformedModuleKeys(): void
    {
        foreach ([str_repeat('A', 65), str_repeat('A', 257), "invalid\xFFutf8"] as $key) {
            $this->assertRejected(static function () use ($key): void {
                (new CmsModuleState())->setModuleKey($key);
            });
        }
    }

    public function testVersionBoundariesPreserveValidValues(): void
    {
        $asciiVersion = str_repeat('v', 32);
        $state = (new CmsModuleState())->setModuleKey('video')->install($asciiVersion);

        self::assertSame($asciiVersion, $state->getInstalledVersion());

        $multibyteVersion = str_repeat('🎮', 32);
        $state->updateVersion($multibyteVersion);
        self::assertSame($multibyteVersion, $state->getInstalledVersion());
    }

    public function testRejectedInstallVersionsLeaveLifecycleStateUnchanged(): void
    {
        $state = (new CmsModuleState())->setModuleKey('video')->removePackage();
        $updatedAt = $state->getUpdatedAt();

        foreach ([str_repeat('v', 33), str_repeat('v', 129), "invalid\xFFutf8"] as $version) {
            $this->assertRejected(static function () use ($state, $version): void {
                $state->install($version);
            });

            self::assertFalse($state->isInstalled());
            self::assertFalse($state->isEnabled());
            self::assertNull($state->getInstalledVersion());
            self::assertNull($state->getInstalledAt());
            self::assertSame($updatedAt, $state->getUpdatedAt());
        }
    }

    public function testRejectedUpdateVersionsLeaveStoredVersionAndTimestampUnchanged(): void
    {
        $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('1.0.0');
        $updatedAt = $state->getUpdatedAt();

        foreach ([str_repeat('v', 33), str_repeat('v', 129), "invalid\xFFutf8"] as $version) {
            $this->assertRejected(static function () use ($state, $version): void {
                $state->updateVersion($version);
            });

            self::assertSame('1.0.0', $state->getInstalledVersion());
            self::assertSame($updatedAt, $state->getUpdatedAt());
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('An out-of-bound CMS module state value must be rejected.');
    }
}
