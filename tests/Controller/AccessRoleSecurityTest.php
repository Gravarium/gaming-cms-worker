<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Repository\AccessRoleRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessRoleSecurityTest extends WebTestCase
{
    public function testUserManagerCannotEditRoleWithPermissionsTheyDoNotOwn(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'role-boundary')->setPermissions([CmsPermission::USERS]);
        $role = (new AccessRole())
            ->setKey('higher-role-'.bin2hex(random_bytes(3)))
            ->setName('Higher role')
            ->setPermissions([CmsPermission::SETTINGS]);
        $this->em($client)->persist($role);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/access-roles/'.$role->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUserManagerCannotCreateRoleWithPermissionTheyDoNotOwn(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'role-create-boundary')->setPermissions([CmsPermission::USERS]);
        $client->loginUser($user);
        $key = 'forbidden-role-'.bin2hex(random_bytes(3));

        $crawler = $client->request('GET', '/admin/access-roles/new');
        $form = $crawler->selectButton('Rolle speichern')->form();
        $values = $form->getPhpValues();
        $values['access_role']['key'] = $key;
        $values['access_role']['name'] = 'Forbidden role';
        $values['access_role']['permissions'] = [CmsPermission::SETTINGS];
        $client->request('POST', '/admin/access-roles/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'nur Berechtigungen geben');
        self::assertNull($client->getContainer()->get(AccessRoleRepository::class)->findOneBy(['key' => $key]));
    }

    public function testUserCannotEscalateThroughRoleAssignedToOwnAccount(): void
    {
        $client = static::createClient();
        $role = (new AccessRole())
            ->setKey('self-manager-'.bin2hex(random_bytes(3)))
            ->setName('Self manager')
            ->setPermissions([CmsPermission::USERS]);
        $user = $this->createUser($client, 'role-self')->addAccessRole($role);
        $this->em($client)->persist($role);
        $this->em($client)->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/access-roles/'.$role->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Rolle speichern')->form();
        $values = $form->getPhpValues();
        $values['access_role']['permissions'] = [CmsPermission::USERS, CmsPermission::SETTINGS];
        $client->request('POST', '/admin/access-roles/'.$role->getId().'/edit', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'dir selbst zugewiesenen Rolle');

        $stored = $client->getContainer()->get(AccessRoleRepository::class)->find($role->getId());
        self::assertInstanceOf(AccessRole::class, $stored);
        self::assertSame([CmsPermission::USERS], $stored->getPermissions());
        self::assertFalse($user->hasPermission(CmsPermission::SETTINGS));
    }

    public function testUserManagerCanEditRoleNotAssignedToSelf(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'role-manager')->setPermissions([CmsPermission::USERS]);
        $role = (new AccessRole())
            ->setKey('managed-role-'.bin2hex(random_bytes(3)))
            ->setName('Managed role')
            ->setPermissions([CmsPermission::USERS]);
        $this->em($client)->persist($role);
        $this->em($client)->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/access-roles/'.$role->getId().'/edit');
        $form = $crawler->selectButton('Rolle speichern')->form([
            'access_role[name]' => 'Updated role',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/access-roles');
        $stored = $client->getContainer()->get(AccessRoleRepository::class)->find($role->getId());
        self::assertInstanceOf(AccessRole::class, $stored);
        self::assertSame('Updated role', $stored->getName());
    }

    public function testRoleDeletionRequiresCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'role-delete')->setPermissions([CmsPermission::USERS]);
        $role = (new AccessRole())->setKey('delete-role-'.bin2hex(random_bytes(3)))->setName('Delete me');
        $this->em($client)->persist($role);
        $this->em($client)->flush();
        $roleId = $role->getId();
        self::assertNotNull($roleId);
        $client->loginUser($user);

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($client->getContainer()->get(AccessRoleRepository::class)->find($roleId));

        $crawler = $client->request('GET', '/admin/access-roles');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[action="/admin/access-roles/'.$roleId.'/delete"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/access-roles');
        self::assertNull($client->getContainer()->get(AccessRoleRepository::class)->find($roleId));
    }

    private function createUser(KernelBrowser $client, string $label): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('User '.$label)
            ->setPassword('unused-test-hash');
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
