<?php

declare(strict_types=1);

namespace App\Tests\Module;

use App\Module\CmsModuleCatalog;
use PHPUnit\Framework\TestCase;

final class CmsModulePackageCatalogTest extends TestCase
{
    public function testEveryPackageHasValidVersionAndResolvableDependencies(): void
    {
        $modules = (new CmsModuleCatalog())->all();

        self::assertNotEmpty($modules);
        foreach ($modules as $key => $module) {
            self::assertSame($key, $module['key']);
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $key);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $module['version']);
            self::assertNotContains($key, $module['dependencies']);
            foreach ($module['dependencies'] as $dependency) {
                self::assertArrayHasKey($dependency, $modules);
            }
        }
    }

    public function testDependencyGraphHasNoCycles(): void
    {
        $modules = (new CmsModuleCatalog())->all();
        foreach (array_keys($modules) as $key) {
            $this->visit($key, $modules, []);
        }
        self::addToAssertionCount(1);
    }

    /** @param array<string, array<string, mixed>> $modules
     *  @param array<string, true> $path
     */
    private function visit(string $key, array $modules, array $path): void
    {
        self::assertArrayNotHasKey($key, $path, 'Module dependency cycle detected.');
        $path[$key] = true;
        foreach ($modules[$key]['dependencies'] as $dependency) {
            $this->visit($dependency, $modules, $path);
        }
    }
}
