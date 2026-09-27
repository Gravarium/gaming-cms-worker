<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AccessRoleRepository;
use App\Repository\AuditLogRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAccessRoleDelegationSecurityTest extends WebTestCase
{
    public function testUserManagerCannotCreateRoleWithPermissionTheyDoNotOwn(): void
    {
        $client = static::createClient();
        $manager = $this->createManager($client);
        $managerId = $manager->getId();
        self::assertNotNull($managerId);
        $key = 'delegation-create-'.bin2hex(random_bytes(6));
        $client->loginUser($manager);

        try {
            $createdAuditCount = $this->auditCount($client, $manager, 'access_role.created');
            $crawler = $client->request('GET', '/admin/access-roles/new');
            $form = $crawler->selectButton('Rolle speichern')->form();
            $values = $form->getPhpValues();
            $values['access_role']['key'] = $key;
            $values['access_role']['name'] = 'Delegation create test';
            $values['access_role']['description'] = 'Synthetic security regression fixture';
            $values['access_role']['permissions'] = [CmsPermission::SETTINGS];
            $values['access_role']['active'] = '1';

            $client->request('POST', '/admin/access-roles/new', $values);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'nur Berechtigungen geben');
            self::assertNull($client->getContainer()->get(AccessRoleRepository::class)->findOneBy(['key' => $key]));
            self::assertSame(
                $createdAuditCount,
                $this->auditCount($client, $manager, 'access_role.created'),
                'A rejected role creation must not record an audit event.',
            );
        } finally {
            $this->cleanupFixtures($client, $key, $managerId);
        }
    }

    public function testUserManagerCannotAddUnownedPermissionToRoleTheyCanManage(): void
    {
        $client = static::createClient();
        $manager = $this->createManager($client);
        $managerId = $manager->getId();
        self::assertNotNull($managerId);
        $key = 'delegation-edit-'.bin2hex(random_bytes(6));
        $role = (new AccessRole())
            ->setKey($key)
            ->setName('Delegation edit test')
            ->setDescription('Original synthetic description')
            ->setPermissions([CmsPermission::USERS])
            ->setActive(true);
        $entityManager = $this->entityManager($client);
        $entityManager->persist($role);
        $entityManager->flush();
        $roleId = $role->getId();
        self::assertNotNull($roleId);
        $client->loginUser($manager);

        try {
            $updatedAuditCount = $this->auditCount($client, $manager, 'access_role.updated');
            $crawler = $client->request('GET', '/admin/access-roles/'.$roleId.'/edit');
            $form = $crawler->selectButton('Rolle speichern')->form();
            $values = $form->getPhpValues();
            $values['access_role']['permissions'] = [CmsPermission::USERS, CmsPermission::SETTINGS];

            $client->request('POST', '/admin/access-roles/'.$roleId.'/edit', $values);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'nur Berechtigungen geben');
            self::assertSame(
                $updatedAuditCount,
                $this->auditCount($client, $manager, 'access_role.updated'),
                'A rejected role edit must not record an audit event.',
            );

            $entityManager->clear();
            $storedRole = $client->getContainer()->get(AccessRoleRepository::class)->find($roleId);
            self::assertInstanceOf(AccessRole::class, $storedRole);
            self::assertSame('Delegation edit test', $storedRole->getName());
            self::assertSame('Original synthetic description', $storedRole->getDescription());
            self::assertTrue($storedRole->isActive());
            self::assertSame([CmsPermission::USERS], $storedRole->getPermissions());
        } finally {
            $this->cleanupFixtures($client, $key, $managerId);
        }
    }

    private function createManager(KernelBrowser $client): User
    {
        $manager = (new User())
            ->setEmail('access-role-manager-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Access role manager')
            ->setPermissions([CmsPermission::USERS])
            ->setPassword('unused-test-hash');
        $this->entityManager($client)->persist($manager);
        $this->entityManager($client)->flush();

        return $manager;
    }

    private function auditCount(KernelBrowser $client, User $actor, string $action): int
    {
        return $client->getContainer()->get(AuditLogRepository::class)->count([
            'actor' => $actor,
            'action' => $action,
        ]);
    }

    private function cleanupFixtures(KernelBrowser $client, string $roleKey, int $managerId): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        $auditLogs = $client->getContainer()->get(AuditLogRepository::class);
        foreach (['access_role.created', 'access_role.updated'] as $action) {
            foreach ($auditLogs->findBy(['action' => $action]) as $auditLog) {
                if ($auditLog instanceof AuditLog && ($auditLog->getContext()['key'] ?? null) === $roleKey) {
                    $entityManager->remove($auditLog);
                }
            }
        }

        $role = $client->getContainer()->get(AccessRoleRepository::class)->findOneBy(['key' => $roleKey]);
        if ($role instanceof AccessRole) {
            $entityManager->remove($role);
        }

        $manager = $entityManager->find(User::class, $managerId);
        if ($manager instanceof User) {
            $entityManager->remove($manager);
        }

        $entityManager->flush();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
