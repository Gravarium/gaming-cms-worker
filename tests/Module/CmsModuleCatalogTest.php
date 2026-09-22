<?php

declare(strict_types=1);
namespace App\Tests\Module;
use App\Module\CmsModuleCatalog;
use PHPUnit\Framework\TestCase;
final class CmsModuleCatalogTest extends TestCase
{
    public function testEveryDependencyExistsAndCoreIsRequired(): void { $modules=(new CmsModuleCatalog())->all(); self::assertTrue($modules['core']['required']); self::assertCount(9,$modules); foreach($modules as $module){foreach($module['dependencies'] as $dependency){self::assertArrayHasKey($dependency,$modules);}} }
    public function testFeatureRouteFamiliesAreOwnedByTheirModules(): void { $modules=(new CmsModuleCatalog())->all(); self::assertContains('app_admin_category',$modules['content']['routePrefixes']); self::assertContains('app_admin_menu',$modules['content']['routePrefixes']); self::assertContains('app_content',$modules['content']['routePrefixes']); self::assertContains('app_gaming',$modules['gaming']['routePrefixes']); self::assertContains('app_guild',$modules['gaming']['routePrefixes']); self::assertContains('app_admin_guild',$modules['gaming']['routePrefixes']); self::assertContains('app_admin_access_role',$modules['users']['routePrefixes']); self::assertContains('app_video',$modules['video']['routePrefixes']); self::assertContains('app_admin_notification',$modules['notifications']['routePrefixes']); self::assertContains('app_admin_queue',$modules['operations']['routePrefixes']); }
    public function testRoutePrefixesAreUniqueAcrossModules(): void { $seen=[]; foreach((new CmsModuleCatalog())->all() as $module){foreach($module['routePrefixes'] as $prefix){self::assertArrayNotHasKey($prefix,$seen,sprintf('Route prefix "%s" belongs to more than one module.',$prefix)); $seen[$prefix]=$module['key'];}} self::assertNotEmpty($seen); }
}
