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

final class AdminAccessRoleMutationSecurityTest extends WebTestCase
{
    public function testContentManagerCannotViewOrForgeAccessRoleCreation(): void
    {
        $client = static::createClient();
        $key = 'forged-'.bin2hex(random_bytes(5));
        $client->loginUser($this->createUser($client, [CmsPermission::CONTENT]));

        $client->request('GET', '/admin/access-roles');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/access-roles/new', [
            'access_role' => [
                'key' => $key,
                'name' => 'Forged role',
                'description' => '',
                'permissions' => [CmsPermission::USERS],
                'active' => '1',
                '_token' => 'forged-token',
            ],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager($client)->clear();
        self::assertNull($this->roles($client)->findOneBy(['key' => $key]));
    }

    public function testMissingAndInvalidCsrfCannotCreateAccessRole(): void
    {
        $client = static::createClient();
        $key = 'csrf-new-'.bin2hex(random_bytes(5));
        $client->loginUser($this->createUser($client, [CmsPermission::USERS]));

        foreach ([null, 'forged-token'] as $token) {
            $formData = [
                'key' => $key,
                'name' => 'Rejected role',
                'description' => '',
                'permissions' => [CmsPermission::USERS],
                'active' => '1',
            ];
            if ($token !== null) {
                $formData['_token'] = $token;
            }

            $client->request('POST', '/admin/access-roles/new', ['access_role' => $formData]);
            self::assertResponseStatusCodeSame(422);

            $this->entityManager($client)->clear();
            self::assertNull($this->roles($client)->findOneBy(['key' => $key]));
        }
    }

    public function testMissingAndInvalidCsrfCannotEditAccessRole(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($client, [CmsPermission::USERS]));
        $key = 'csrf-edit-'.bin2hex(random_bytes(5));
        $role = (new AccessRole())
            ->setKey($key)
            ->setName('Original role')
            ->setDescription('Stable description')
            ->setPermissions([CmsPermission::USERS])
            ->setActive(true);
        $this->entityManager($client)->persist($role);
        $this->entityManager($client)->flush();
        $roleId = $role->getId();
        self::assertNotNull($roleId);

        try {
            foreach ([null, 'forged-token'] as $token) {
                $formData = [
                    'key' => $key,
                    'name' => 'Forged rename',
                    'description' => 'Changed without a valid token',
                    'permissions' => [CmsPermission::USERS],
                    'active' => '1',
                ];
                if ($token !== null) {
                    $formData['_token'] = $token;
                }

                $client->request('POST', '/admin/access-roles/'.$roleId.'/edit', ['access_role' => $formData]);
                self::assertResponseStatusCodeSame(422);

                $this->entityManager($client)->clear();
                $stored = $this->entityManager($client)->find(AccessRole::class, $roleId);
                self::assertInstanceOf(AccessRole::class, $stored);
                self::assertSame('Original role', $stored->getName());
                self::assertSame('Stable description', $stored->getDescription());
                self::assertSame([CmsPermission::USERS], $stored->getPermissions());
            }
        } finally {
            $this->entityManager($client)->clear();
            $stored = $this->entityManager($client)->find(AccessRole::class, $roleId);
            if ($stored instanceof AccessRole) {
                $this->entityManager($client)->remove($stored);
                $this->entityManager($client)->flush();
            }
        }
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('access-role-security-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Access role mutation security test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function roles(KernelBrowser $client): AccessRoleRepository
    {
        return $client->getContainer()->get(AccessRoleRepository::class);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
