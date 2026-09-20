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
}
