<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentLayoutLifecycleTest extends WebTestCase
{
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
}
