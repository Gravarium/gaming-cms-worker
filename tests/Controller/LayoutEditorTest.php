<?php

declare(strict_types=1);
namespace App\Tests\Controller;

use App\Entity\PageLayout;
use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LayoutEditorTest extends WebTestCase
{
    private function client(bool $settings = true): KernelBrowser
    {
        $client=static::createClient();$em=$client->getContainer()->get(EntityManagerInterface::class);
        $user=(new User())->setEmail('layout-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('Layout test')->setPermissions($settings?[CmsPermission::ACCESS,CmsPermission::SETTINGS]:[CmsPermission::ACCESS])->verifyEmail();
        $em->persist($user);$em->flush();$client->loginUser($user);return $client;
    }
    private function clearLayout(KernelBrowser $client): void
    {
        $em=$client->getContainer()->get(EntityManagerInterface::class);$record=$em->find(PageLayout::class,'home');if($record!==null){$em->remove($record);$em->flush();}$em->clear();
    }
    public function testUnauthorizedEditorAndWriteAreForbidden(): void
    {
        $client=$this->client(false);$client->request('GET','/admin/layout/home');self::assertResponseStatusCodeSame(403);
        $client->request('POST','/admin/layout/home/save');self::assertResponseStatusCodeSame(403);
    }
    public function testMissingCsrfCannotSaveOrPreview(): void
    {
        $client=$this->client();$client->request('POST','/admin/layout/home/save');self::assertResponseStatusCodeSame(403);
        $client->request('POST','/admin/layout/home/preview');self::assertResponseStatusCodeSame(403);
    }
    public function testPreviewDoesNotSaveAndPublishedTextIsEscapedAndConflictRejected(): void
    {
        $client=$this->client();$this->clearLayout($client);
        try {
            $crawler=$client->request('GET','/admin/layout/home');self::assertResponseIsSuccessful();
            $dir=dirname(__DIR__,2).'/var/layout-preview';if(!is_dir($dir))mkdir($dir,0770,true);
            file_put_contents($dir.'/editor.html',(string)$client->getResponse()->getContent());
            $token=$crawler->filter('[data-layout-editor-token-value]')->attr('data-layout-editor-token-value');
            $doc=$client->getContainer()->get(LayoutValidator::class)->defaults('gravarium-fantasy')->toArray();
            $doc['widgets'][0]['config']['title']='<script>alert("xss")</script>';
            $payload=json_encode(['version'=>0,'document'=>$doc],JSON_THROW_ON_ERROR);
            $client->request('POST','/admin/layout/home/preview',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],$payload);
            self::assertResponseIsSuccessful();self::assertSelectorNotExists('.portal-hero-copy h1 script');
            self::assertStringNotContainsString('<script>alert("xss")</script>', (string)$client->getResponse()->getContent());
            self::assertStringContainsString('&lt;script&gt;', (string)$client->getResponse()->getContent());
            self::assertNull($client->getContainer()->get(EntityManagerInterface::class)->find(PageLayout::class,'home'));
            $client->request('POST','/admin/layout/home/save',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],$payload);self::assertResponseIsSuccessful();
            $client->request('GET','/');self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','<script>');self::assertSelectorExists('.theme-gravarium-fantasy');
            $client->request('POST','/admin/layout/home/save',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],$payload);self::assertResponseStatusCodeSame(409);
        } finally { $this->clearLayout($client); }
    }
    public function testDisabledModuleWidgetIsRetainedAndHidden(): void
    {
        $client=$this->client();$this->clearLayout($client);$em=$client->getContainer()->get(EntityManagerInterface::class);
        try {
            $validator=$client->getContainer()->get(LayoutValidator::class);$doc=$validator->defaults('nebula')->toArray();
            $doc['widgets'][]=['id'=>'video-fixture','type'=>'video.latest','region'=>'right-sidebar','enabled'=>true,'config'=>[]];
            $record=new PageLayout('home');$record->replace($validator->validate($doc)->toArray());$em->persist($record);
            $state=$em->find(CmsModuleState::class,'video')??(new CmsModuleState())->setModuleKey('video')->updateVersion('1.0.0');$state->setEnabled(false);$em->persist($state);$em->flush();
            $client->request('GET','/');self::assertResponseIsSuccessful();self::assertSelectorNotExists('.widget-video-latest');
            $crawler=$client->request('GET','/admin/layout/home');self::assertResponseIsSuccessful();
            $editor=json_decode((string)$crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),true,512,JSON_THROW_ON_ERROR);self::assertSame('video.latest',$editor['document']['widgets'][1]['type']);
        } finally {
            $em=$client->getContainer()->get(EntityManagerInterface::class);$state=$em->find(CmsModuleState::class,'video');if($state!==null)$em->remove($state);$em->flush();$this->clearLayout($client);
        }
    }
}
