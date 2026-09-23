<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ContentLayoutLifecycleTest extends WebTestCase
{
    public function testPageToNewsEditRemovesLayout(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$user,$entry] = $this->persistEntry($em, ContentEntry::TYPE_PAGE, 'layout-page-to-news');
        $layout = new PageLayout('page-'.$entry->getId());$layout->replace(['widgets'=>[]]);$em->persist($layout);$em->flush();
        $client->loginUser($user);
        $crawler=$client->request('GET','/admin/content/'.$entry->getId().'/edit');self::assertResponseIsSuccessful();
        $form=$crawler->selectButton('Speichern')->form();$form['content_entry[type]']->select(ContentEntry::TYPE_NEWS);$client->submit($form);self::assertResponseRedirects();
        $em->clear();
        self::assertNull($em->find(PageLayout::class,'page-'.$entry->getId()));
        self::assertSame(ContentEntry::TYPE_NEWS,$em->find(ContentEntry::class,$entry->getId())?->getType());
    }

    public function testNewsToPageEditDoesNotReactivateHistoricalOrphan(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        [$user,$entry] = $this->persistEntry($em, ContentEntry::TYPE_NEWS, 'layout-news-to-page');
        $layout = new PageLayout('page-'.$entry->getId());$layout->replace(['widgets'=>[]]);$em->persist($layout);$em->flush();
        $client->loginUser($user);
        $crawler=$client->request('GET','/admin/content/'.$entry->getId().'/edit');self::assertResponseIsSuccessful();
        $form=$crawler->selectButton('Speichern')->form();$form['content_entry[type]']->select(ContentEntry::TYPE_PAGE);$client->submit($form);self::assertResponseRedirects();
        $em->clear();
        self::assertNull($em->find(PageLayout::class,'page-'.$entry->getId()));
        self::assertSame(ContentEntry::TYPE_PAGE,$em->find(ContentEntry::class,$entry->getId())?->getType());
    }

    public function testRestoringNewsRevisionRemovesCurrentPageLayout(): void
    {
        $client=static::createClient();$em=$client->getContainer()->get(EntityManagerInterface::class);
        [$user,$entry]=$this->persistEntry($em,ContentEntry::TYPE_NEWS,'layout-revision');
        $revision=new ContentRevision($entry,1,$user);$em->persist($revision);$entry->setType(ContentEntry::TYPE_PAGE);$em->flush();
        $layout=new PageLayout('page-'.$entry->getId());$layout->replace(['widgets'=>[]]);$em->persist($layout);$em->flush();
        $client->loginUser($user);
        $token=$client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('restore-content-'.$entry->getId().'-'.$revision->getId())->getValue();
        $client->request('POST','/admin/content/'.$entry->getId().'/revisions/'.$revision->getId().'/restore',['_token'=>$token]);self::assertResponseRedirects();
        $em->clear();
        self::assertNull($em->find(PageLayout::class,'page-'.$entry->getId()));
        self::assertSame(ContentEntry::TYPE_NEWS,$em->find(ContentEntry::class,$entry->getId())?->getType());
    }

    public function testPurgingPageRemovesItsLayout(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('layout-purge-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Layout purge')->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])->verifyEmail();
        $entry = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_PAGE)
            ->setTitle('Page for purge')->setSlug('layout-purge-'.bin2hex(random_bytes(6)))->setBody('Page body');
        $entry->trash();
        $em->persist($user);
        $em->persist($entry);
        $em->flush();
        $id = $entry->getId();
        self::assertNotNull($id);
        $layout = new PageLayout('page-'.$id);
        $layout->replace(['widgets' => []]);
        $em->persist($layout);
        $em->flush();
        $client->loginUser($user);
        $crawler = $client->request('GET', '/admin/content?status=trashed');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/admin/content/'.$id.'/purge"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/admin/content/'.$id.'/purge', ['_token' => $token]);
        self::assertResponseRedirects();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(PageLayout::class, 'page-'.$id));
        self::assertNull($em->find(ContentEntry::class, $id));
    }

    /** @return array{User,ContentEntry} */
    private function persistEntry(EntityManagerInterface $em,string $type,string $slugPrefix): array
    {
        $user=(new User())->setEmail($slugPrefix.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Layout lifecycle')->setPermissions([CmsPermission::ACCESS,CmsPermission::CONTENT])->verifyEmail();
        $entry=(new ContentEntry())->setAuthor($user)->setType($type)->setTitle('Layout lifecycle')
            ->setSlug($slugPrefix.'-'.bin2hex(random_bytes(6)))->setBody('Page body');
        $em->persist($user);$em->persist($entry);$em->flush();
        return [$user,$entry];
    }
}
