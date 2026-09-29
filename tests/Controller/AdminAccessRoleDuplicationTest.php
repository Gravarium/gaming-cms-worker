<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAccessRoleDuplicationTest extends WebTestCase
{
    private ?KernelBrowser $client = null;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $roleIds = [];

    public function testManagerCanSaveAnInactiveUnassignedCopyWithUniqueKey(): void
    {
        $client = static::createClient();
        $this->client = $client;
        $actor = $this->createUser($client, 'copy', [
            CmsPermission::USERS,
            CmsPermission::CONTENT,
            CmsPermission::GAMING,
        ]);
        $sourceKey = 'source-copy-'.bin2hex(random_bytes(4));
        $source = $this->createRole($client, $sourceKey, 'Source role', [
            CmsPermission::CONTENT,
            CmsPermission::GAMING,
        ]);
        $sourceId = $source->getId();
        if ($sourceId === null) {
            throw new \LogicException('Source role was not persisted.');
        }
        $this->createRole($client, $sourceKey.'-copy', 'Existing copy key', [CmsPermission::CONTENT]);
        $client->loginUser($actor);

        $editPage = $client->request('GET', '/admin/access-roles/'.$sourceId.'/edit');
        self::assertResponseIsSuccessful();
        $copyHref = $editPage->filter('a[href="/admin/access-roles/'.$sourceId.'/duplicate"]')->attr('href');
        self::assertNotNull($copyHref);

        $copyPage = $client->request('GET', $copyHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rolle kopieren');
        self::assertSelectorTextContains('body', 'Die Kopie startet inaktiv und ohne Benutzerzuweisungen.');
        self::assertSame('/admin/access-roles/new', $copyPage->filter('form')->attr('action'));

        $copyKey = (string) $copyPage->filter('input[name="access_role[key]"]')->attr('value');
        self::assertSame($sourceKey.'-copy-2', $copyKey);
        self::assertSame('Source role (Kopie)', $copyPage->filter('input[name="access_role[name]"]')->attr('value'));
        self::assertSame('checked', $copyPage->filter('input[name="access_role[permissions][]"][value="'.CmsPermission::CONTENT.'"]')->attr('checked'));
        self::assertSame('checked', $copyPage->filter('input[name="access_role[permissions][]"][value="'.CmsPermission::GAMING.'"]')->attr('checked'));
        self::assertNull($copyPage->filter('input[name="access_role[active]"]')->attr('checked'));
        self::assertNull($this->em($client)->getRepository(AccessRole::class)->findOneBy(['key' => $copyKey]));

        $form = $copyPage->selectButton('Rolle speichern')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/access-roles');

        $entityManager = $this->em($client);
        $entityManager->clear();
        $storedCopy = $entityManager->getRepository(AccessRole::class)->findOneBy(['key' => $copyKey]);
        self::assertInstanceOf(AccessRole::class, $storedCopy);
        $copyId = $storedCopy->getId();
        if ($copyId !== null) {
            $this->roleIds[] = $copyId;
        }
        self::assertSame('Source role (Kopie)', $storedCopy->getName());
        self::assertSame('Existing role description.', $storedCopy->getDescription());
        self::assertSame([CmsPermission::CONTENT, CmsPermission::GAMING], $storedCopy->getPermissions());
        self::assertFalse($storedCopy->isActive());
        self::assertTrue($storedCopy->getUsers()->isEmpty());

        $storedSource = $entityManager->find(AccessRole::class, $sourceId);
        self::assertInstanceOf(AccessRole::class, $storedSource);
        self::assertSame($sourceKey, $storedSource->getKey());
        self::assertSame('Source role', $storedSource->getName());
        self::assertSame([CmsPermission::CONTENT, CmsPermission::GAMING], $storedSource->getPermissions());
        self::assertTrue($storedSource->isActive());

        $storedActor = $entityManager->find(User::class, $actor->getId());
        self::assertInstanceOf(User::class, $storedActor);
        $creationLogs = $entityManager->getRepository(AuditLog::class)->findBy([
            'actor' => $storedActor,
            'action' => 'access_role.created',
        ]);
        self::assertCount(1, $creationLogs);
    }

    public function testUserWithoutUserManagementPermissionCannotOpenCopyFlow(): void
    {
        $client = static::createClient();
        $this->client = $client;
        $actor = $this->createUser($client, 'no-manage', []);
        $source = $this->createRole($client, 'source-no-manage-'.bin2hex(random_bytes(4)), 'Protected source', [CmsPermission::CONTENT]);
        $sourceId = $source->getId();
        if ($sourceId === null) {
            throw new \LogicException('Source role was not persisted.');
        }
        $client->loginUser($actor);

        $client->request('GET', '/admin/access-roles/'.$sourceId.'/duplicate');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em($client)->getRepository(AccessRole::class)->findOneBy(['key' => $source->getKey().'-copy']));
    }

    public function testManagerCannotCopyPermissionsTheyDoNotOwn(): void
    {
        $client = static::createClient();
        $this->client = $client;
        $actor = $this->createUser($client, 'delegation', [CmsPermission::USERS]);
        $source = $this->createRole($client, 'source-denied-'.bin2hex(random_bytes(4)), 'Protected source', [CmsPermission::SETTINGS]);
        $sourceId = $source->getId();
        if ($sourceId === null) {
            throw new \LogicException('Source role was not persisted.');
        }
        $client->loginUser($actor);

        $client->request('GET', '/admin/access-roles/'.$sourceId.'/duplicate');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em($client)->getRepository(AccessRole::class)->findOneBy(['key' => $source->getKey().'-copy']));
    }

    public function testCopiedPermissionsCannotBeExpandedBeyondDelegablePermissions(): void
    {
        $client = static::createClient();
        $this->client = $client;
        $actor = $this->createUser($client, 'tamper', [CmsPermission::USERS, CmsPermission::CONTENT]);
        $sourceKey = 'source-tamper-'.bin2hex(random_bytes(4));
        $source = $this->createRole($client, $sourceKey, 'Source role', [CmsPermission::CONTENT]);
        $sourceId = $source->getId();
        if ($sourceId === null) {
            throw new \LogicException('Source role was not persisted.');
        }
        $client->loginUser($actor);

        $copyPage = $client->request('GET', '/admin/access-roles/'.$sourceId.'/duplicate');
        self::assertResponseIsSuccessful();
        $form = $copyPage->selectButton('Rolle speichern')->form();
        $values = $form->getPhpValues();
        $copyKey = (string) $copyPage->filter('input[name="access_role[key]"]')->attr('value');
        $values['access_role']['permissions'] = [CmsPermission::CONTENT, CmsPermission::SETTINGS];
        $client->request('POST', '/admin/access-roles/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'nur Berechtigungen geben');
        $entityManager = $this->em($client);
        $entityManager->clear();
        self::assertNull($entityManager->getRepository(AccessRole::class)->findOneBy(['key' => $copyKey]));
        $storedSource = $entityManager->find(AccessRole::class, $sourceId);
        self::assertInstanceOf(AccessRole::class, $storedSource);
        self::assertSame($sourceKey, $storedSource->getKey());
        self::assertSame([CmsPermission::CONTENT], $storedSource->getPermissions());
    }

    public function testMissingAndInvalidCsrfCannotSaveRoleCopy(): void
    {
        $client = static::createClient();
        $this->client = $client;
        $actor = $this->createUser($client, 'csrf', [CmsPermission::USERS, CmsPermission::CONTENT]);
        $sourceKey = 'source-csrf-'.bin2hex(random_bytes(4));
        $source = $this->createRole($client, $sourceKey, 'CSRF source', [CmsPermission::CONTENT]);
        $sourceId = $source->getId();
        if ($sourceId === null) {
            throw new \LogicException('Source role was not persisted.');
        }
        $client->loginUser($actor);

        $copyPage = $client->request('GET', '/admin/access-roles/'.$sourceId.'/duplicate');
        self::assertResponseIsSuccessful();
        $copyKey = (string) $copyPage->filter('input[name="access_role[key]"]')->attr('value');
        $form = $copyPage->selectButton('Rolle speichern')->form();
        $values = $form->getPhpValues();
        unset($values['access_role']['_token']);
        $client->request('POST', '/admin/access-roles/new', $values);
        self::assertResponseStatusCodeSame(422);

        $values['access_role']['_token'] = 'invalid-token';
        $client->request('POST', '/admin/access-roles/new', $values);
        self::assertResponseStatusCodeSame(422);

        $entityManager = $this->em($client);
        $entityManager->clear();
        self::assertNull($entityManager->getRepository(AccessRole::class)->findOneBy(['key' => $copyKey]));
        $storedSource = $entityManager->find(AccessRole::class, $sourceId);
        self::assertInstanceOf(AccessRole::class, $storedSource);
        self::assertSame($sourceKey, $storedSource->getKey());
        self::assertSame([CmsPermission::CONTENT], $storedSource->getPermissions());
        $storedActor = $entityManager->find(User::class, $actor->getId());
        self::assertInstanceOf(User::class, $storedActor);
        self::assertSame([], $entityManager->getRepository(AuditLog::class)->findBy([
            'actor' => $storedActor,
            'action' => 'access_role.created',
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->client instanceof KernelBrowser) {
            $entityManager = $this->em($this->client);
            foreach ($this->userIds as $userId) {
                $entityManager->clear();
                $user = $entityManager->find(User::class, $userId);
                if (!$user instanceof User) {
                    continue;
                }
                $entityManager->createQuery('DELETE FROM App\\Entity\\AuditLog auditLog WHERE auditLog.actor = :user')
                    ->setParameter('user', $user)
                    ->execute();
                $entityManager->createQuery('DELETE FROM App\\Entity\\UserSession userSession WHERE userSession.user = :user')
                    ->setParameter('user', $user)
                    ->execute();
                $entityManager->remove($user);
                $entityManager->flush();
            }
            foreach ($this->roleIds as $roleId) {
                $entityManager->clear();
                $role = $entityManager->find(AccessRole::class, $roleId);
                if ($role instanceof AccessRole) {
                    $entityManager->remove($role);
                    $entityManager->flush();
                }
            }
        }

        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Role copy '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $entityManager = $this->em($client);
        $entityManager->persist($user);
        $entityManager->flush();

        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Test user was not persisted.');
        }
        $this->userIds[] = $userId;

        return $user;
    }

    /** @param list<string> $permissions */
    private function createRole(KernelBrowser $client, string $key, string $name, array $permissions, bool $active = true): AccessRole
    {
        $role = (new AccessRole())
            ->setKey($key)
            ->setName($name)
            ->setDescription('Existing role description.')
            ->setPermissions($permissions)
            ->setActive($active);
        $entityManager = $this->em($client);
        $entityManager->persist($role);
        $entityManager->flush();

        $roleId = $role->getId();
        if ($roleId === null) {
            throw new \LogicException('Test role was not persisted.');
        }
        $this->roleIds[] = $roleId;

        return $role;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}