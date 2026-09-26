<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminAccessRoleDeletionSecurityTest extends WebTestCase
{
    public function testRoleRoutesRequireUserManagementPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, []);
        $roleId = $this->createRole($client);
        $client->loginUser($user);

        $client->request('GET', '/admin/access-roles');

        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', []);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(AccessRole::class, $this->findRole($client, $roleId));
    }

    public function testMissingAndInvalidCsrfTokensCannotDeleteRole(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::USERS]);
        $roleId = $this->createRole($client);
        $client->loginUser($user);

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', []);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(AccessRole::class, $this->findRole($client, $roleId));

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(AccessRole::class, $this->findRole($client, $roleId));
    }

    public function testRenderedTokenDeletesOnlyAnUnassignedNonSystemRole(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::USERS]);
        $roleId = $this->createRole($client);
        $untouchedRoleId = $this->createRole($client);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/access-roles');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($crawler, $roleId);

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/access-roles');
        self::assertNull($this->findRole($client, $roleId));
        self::assertInstanceOf(AccessRole::class, $this->findRole($client, $untouchedRoleId));
    }

    public function testSystemRoleCannotBeDeletedWithPreviouslyRenderedValidCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::USERS]);
        $roleId = $this->createRole($client);
        $client->loginUser($user);

        $draftCrawler = $client->request('GET', '/admin/access-roles');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($draftCrawler, $roleId);

        $role = $this->findRole($client, $roleId);
        self::assertInstanceOf(AccessRole::class, $role);
        $role->setSystemRole(true);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        $protectedCrawler = $client->request('GET', '/admin/access-roles');
        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $protectedCrawler->filter('form[action="/admin/access-roles/'.$roleId.'/delete"]')->count(),
        );

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/access-roles');
        $storedRole = $this->findRole($client, $roleId);
        self::assertInstanceOf(AccessRole::class, $storedRole);
        self::assertTrue($storedRole->isSystemRole());
    }

    public function testAssignedRoleCannotBeDeletedWithPreviouslyRenderedValidCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::USERS]);
        $assignee = $this->createUser($client, []);
        $roleId = $this->createRole($client);
        $client->loginUser($user);

        $draftCrawler = $client->request('GET', '/admin/access-roles');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($draftCrawler, $roleId);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $role = $this->findRole($client, $roleId);
        self::assertInstanceOf(AccessRole::class, $role);
        $assignee->addAccessRole($role);
        $entityManager->flush();
        $entityManager->clear();

        $protectedCrawler = $client->request('GET', '/admin/access-roles');
        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $protectedCrawler->filter('form[action="/admin/access-roles/'.$roleId.'/delete"]')->count(),
        );

        $client->request('POST', '/admin/access-roles/'.$roleId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/access-roles');
        $storedRole = $this->findRole($client, $roleId);
        self::assertInstanceOf(AccessRole::class, $storedRole);
        self::assertCount(1, $storedRole->getUsers());
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('access-role-delete-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Access role deletion test')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createRole(KernelBrowser $client): int
    {
        $suffix = bin2hex(random_bytes(6));
        $role = (new AccessRole())
            ->setKey('test-role-'.$suffix)
            ->setName('Test role '.$suffix);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->flush();
        $id = $role->getId();
        if ($id === null) {
            throw new LogicException('The access-role fixture must have an identifier.');
        }

        return $id;
    }

    private function findRole(KernelBrowser $client, int $id): ?AccessRole
    {
        $role = $client->getContainer()->get(EntityManagerInterface::class)->find(AccessRole::class, $id);

        return $role instanceof AccessRole ? $role : null;
    }

    private function renderedDeleteToken(Crawler $crawler, int $roleId): string
    {
        $selector = sprintf(
            'form[action="/admin/access-roles/%d/delete"] input[name="_token"]',
            $roleId,
        );
        $token = $crawler->filter($selector)->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The role list must render a delete token for an unassigned non-system role.');
        }

        return $token;
    }
}
