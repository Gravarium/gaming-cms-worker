<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Module\CmsModuleCatalog;
use PHPUnit\Framework\TestCase;

final class CmsModuleCatalogTest extends TestCase
{
    public function testEveryDependencyExistsAndCoreIsRequired(): void
    {
        $modules = (new CmsModuleCatalog())->all();
        self::assertTrue($modules['core']['required']);
        self::assertCount(9, $modules);
        foreach ($modules as $module) {
            foreach ($module['dependencies'] as $dependency) { self::assertArrayHasKey($dependency, $modules); }
        }
    }

    public function testFeatureRouteFamiliesAreOwnedByTheirModules(): void
    {
        $modules = (new CmsModuleCatalog())->all();
        self::assertContains('app_admin_category', $modules['content']['routePrefixes']);
        self::assertContains('app_admin_menu', $modules['content']['routePrefixes']);
        self::assertContains('app_gaming', $modules['gaming']['routePrefixes']);
        self::assertContains('app_guild', $modules['gaming']['routePrefixes']);
        self::assertContains('app_video', $modules['video']['routePrefixes']);
    }
}
