<?php
declare(strict_types=1);
namespace App\Tests\Downloads;
use App\Entity\CmsModuleState;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
final class DownloadControllerTest extends WebTestCase
{
    public function testMemberDownloadIsNotVisibleToAnonymousUser():void
    {
        $client=static::createClient();$package=(new DownloadPackage('Members','members','file'))->setVisibility('member');
        $this->em($client)->persist($package);$this->em($client)->flush();
        $client->request('GET','/downloads/members');self::assertResponseStatusCodeSame(404);
    }
    public function testDisabledDownloadModuleFailsClosed():void
    {
        $client=static::createClient();$state=(new CmsModuleState())->setModuleKey('downloads')->updateVersion('1.0.0')->setEnabled(false);
        $this->em($client)->persist($state);$this->em($client)->flush();
        try {
            $client->request('GET','/downloads');self::assertResponseStatusCodeSame(404);
        } finally {
            $this->em($client)->remove($state);$this->em($client)->flush();
        }
    }
    public function testAdminUploadRequiresCsrfAndRejectsExecutableExtension():void
    {
        $client=static::createClient();$this->ensureDownloadsEnabled($client);$user=$this->user($client,[CmsPermission::STORAGE]);$client->loginUser($user);
        $package=new DownloadPackage('Safe','safe','file');$this->em($client)->persist($package);$this->em($client)->flush();$id=$package->getId();self::assertNotNull($id);
        $path=sys_get_temp_dir().'/download-'.bin2hex(random_bytes(5)).'.php';file_put_contents($path,'<?php echo 1;');
        try{
            $client->request('POST','/admin/downloads/'.$id.'/upload',['version'=>'1.0','_token'=>'invalid'],['file'=>new UploadedFile($path,'bad.php','application/x-php',null,true)]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(0,$this->em($client)->getRepository(DownloadVersion::class)->count([]));
        }finally{@unlink($path);}
    }
    public function testValidCsrfStillRejectsExecutableUpload():void
    {
        $client=static::createClient();$this->ensureDownloadsEnabled($client);$user=$this->user($client,[CmsPermission::STORAGE]);$client->loginUser($user);
        $package=new DownloadPackage('Safe2','safe2','file');$this->em($client)->persist($package);$this->em($client)->flush();$id=$package->getId();self::assertNotNull($id);
        $client->request('GET','/');self::assertResponseIsSuccessful();
        $token=$client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('download-upload-'.$id)->getValue();
        $path=sys_get_temp_dir().'/download-'.bin2hex(random_bytes(5)).'.php';file_put_contents($path,'<?php echo 1;');
        try{
            $client->request('POST','/admin/downloads/'.$id.'/upload',['version'=>'1.0','_token'=>$token],['file'=>new UploadedFile($path,'bad.php','application/x-php',null,true)]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0,$this->em($client)->getRepository(DownloadVersion::class)->count([]));
        }finally{@unlink($path);}
    }

    private function ensureDownloadsEnabled(KernelBrowser $client):void
    {
        $state=$this->em($client)->find(CmsModuleState::class,'downloads');
        if($state instanceof CmsModuleState){$state->setEnabled(true);$this->em($client)->flush();}
    }

    private function user(KernelBrowser $client,array $permissions):User{$user=(new User())->setEmail('download-'.bin2hex(random_bytes(5)).'@example.test')->setDisplayName('Download')->setPermissions($permissions)->verifyEmail();$this->em($client)->persist($user);$this->em($client)->flush();return $user;}
    private function em(KernelBrowser $client):EntityManagerInterface{return $client->getContainer()->get(EntityManagerInterface::class);}
}
