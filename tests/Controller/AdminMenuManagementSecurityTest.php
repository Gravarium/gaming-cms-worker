<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MenuItem;
use App\Entity\User;
use App\Repository\MenuItemRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminMenuManagementSecurityTest extends WebTestCase
{
    public function testAnonymousUserCannotAccessMenuManager(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/menu');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedUserWithoutContentPermissionIsDenied(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'unauthorized', []);
        $client->loginUser($user);
        $client->request('GET', '/admin/menu');

        self::assertResponseStatusCodeSame(403);
    }

    public function testContentManagerCanCreateMenuItemAndEmptyTargetIsRejected(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'content-manager', [CmsPermission::CONTENT]);
        $client->loginUser($user);

        $label = 'Community '.$this->suffix();
        $slug = strtolower(str_replace(' ', '-', $label));
        $crawler = $client->request('GET', '/admin/menu/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Speichern')->form([
            'menu_item[label]' => $label,
            'menu_item[url]' => 'https://example.test/'.$slug,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/menu');
        $item = $client->getContainer()->get(MenuItemRepository::class)->findOneBy(['label' => $label]);
        self::assertInstanceOf(MenuItem::class, $item);
        self::assertSame('https://example.test/'.$slug, $item->getUrl());

        $missingTargetLabel = 'No target '.$this->suffix();
        $crawler = $client->request('GET', '/admin/menu/new');
        $form = $crawler->selectButton('Speichern')->form([
            'menu_item[label]' => $missingTargetLabel,
            'menu_item[url]' => '',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Bitte eine veröffentlichte Seite oder eine externe Adresse auswählen.');
        self::assertNull($client->getContainer()->get(MenuItemRepository::class)->findOneBy(['label' => $missingTargetLabel]));
    }

    public function testMenuDeletionRequiresCsrfAndValidTokenDeletesItem(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'deleter', [CmsPermission::CONTENT]);
        $item = (new MenuItem())
            ->setLabel('Delete target '.$this->suffix())
            ->setUrl('https://example.test/menu-target');
        $this->entityManager($client)->persist($item);
        $this->entityManager($client)->flush();
        $id = $item->getId();
        self::assertNotNull($id);
        $client->loginUser($user);

        $client->request('POST', '/admin/menu/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(MenuItem::class, $client->getContainer()->get(MenuItemRepository::class)->find($id));

        $crawler = $client->request('GET', '/admin/menu');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[action="/admin/menu/'.$id.'/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/menu/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/menu');
        $this->entityManager($client)->clear();
        self::assertNull($client->getContainer()->get(MenuItemRepository::class)->find($id));
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('menu-'.$label.'-'.$this->suffix().'@example.test')
            ->setDisplayName('Menu '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(5));
    }
}
