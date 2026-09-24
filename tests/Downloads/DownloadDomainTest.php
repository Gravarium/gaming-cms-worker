<?php
declare(strict_types=1);
namespace App\Tests\Downloads;
use App\Downloads\DownloadAuthorization;
use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadManifestEntry;
use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Security\CmsPermission;
use PHPUnit\Framework\TestCase;
final class DownloadDomainTest extends TestCase
{
    public function testVersionRequiresSafePrivateReferenceAndCleanScanBeforeDelivery():void
    {
        $package=new DownloadPackage('Mod','mod','mod');
        $version=new DownloadVersion($package,'1.0.0','mod-abc.zip',str_repeat('a',64),'2026/09/mod-abc.zip');
        self::assertFalse($version->isDeliverable());
        $version->markScan('clean');
        self::assertTrue($version->isDeliverable());
        $replacement=(new DownloadVersion($package,'1.1.0','mod-def.zip',str_repeat('b',64),'2026/09/mod-def.zip'))->markScan('clean');
        $version->replaceWith($replacement);
        self::assertTrue($version->isObsolete());
        self::assertFalse($version->isDeliverable());
    }
    public function testDependencyConflictAndModpackManifestAreBounded():void
    {
        $mod=new DownloadPackage('Mod','mod','mod');$lib=new DownloadPackage('Library','library','addon');
        $version=new DownloadVersion($mod,'1.0','mod.zip',str_repeat('a',64),'x/mod.zip');
        $dependency=new DownloadDependency($version,$lib,'conflicts','<2.0');
        self::assertSame('conflicts',$dependency->getKind());
        $modpack=new DownloadPackage('Pack','pack','modpack');
        $packVersion=new DownloadVersion($modpack,'1.0','pack.zip',str_repeat('b',64),'x/pack.zip');
        $manifest=new DownloadManifestEntry($packVersion,$mod,'^1.0');
        self::assertTrue($manifest->isRequired());
    }
    public function testMirrorPolicyRejectsCredentialsAndPlainHttp():void
    {
        $version=new DownloadVersion(new DownloadPackage('A','a','file'),'1','a.zip',str_repeat('a',64),'x/a.zip');
        new DownloadMirror($version,'https://mirror.example.test/a.zip',true);
        $this->expectException(\InvalidArgumentException::class);
        new DownloadMirror($version,'http://user:secret@mirror.example.test/a.zip');
    }
    public function testVisibilityAuthorizationFailsClosed():void
    {
        $policy=new DownloadAuthorization();$package=(new DownloadPackage('Private','private','file'))->setVisibility('admin');
        self::assertFalse($policy->canDownload($package,null));
        $member=(new User())->setEmail('member@example.test')->setDisplayName('Member')->verifyEmail();
        self::assertFalse($policy->canDownload($package,$member));
        $admin=(new User())->setEmail('admin@example.test')->setDisplayName('Admin')->setPermissions([CmsPermission::STORAGE])->verifyEmail();
        self::assertTrue($policy->canDownload($package,$admin));
        $package->setEnabled(false);self::assertFalse($policy->canDownload($package,$admin));
    }
    public function testStorageReferenceRejectsTraversal():void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DownloadVersion(new DownloadPackage('A','a','file'),'1','a.zip',str_repeat('a',64),'../a.zip');
    }
}
