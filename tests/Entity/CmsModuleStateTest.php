<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CmsModuleState;
use PHPUnit\Framework\TestCase;

final class CmsModuleStateTest extends TestCase
{
    public function testInstallUpdateAndRemovalPreserveLifecycleMetadata(): void
    {
        $state = (new CmsModuleState())->setModuleKey('video');
        $state->install('1.0.0');

        self::assertTrue($state->isInstalled());
        self::assertFalse($state->isEnabled());
        self::assertSame('1.0.0', $state->getInstalledVersion());
        self::assertNotNull($state->getInstalledAt());

        $installedAt = $state->getInstalledAt();
        $state->updateVersion('1.1.0')->setEnabled(true);
        self::assertSame('1.1.0', $state->getInstalledVersion());
        self::assertTrue($state->isEnabled());

        $state->removePackage();
        self::assertFalse($state->isInstalled());
        self::assertFalse($state->isEnabled());
        self::assertSame('1.1.0', $state->getInstalledVersion());
        self::assertSame($installedAt, $state->getInstalledAt());
    }
}
