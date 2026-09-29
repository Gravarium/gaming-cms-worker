<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserTargetPrivilegeSecurityTest extends WebTestCase
{
    public function testScopedUserManagerCannotViewSessionsOrEditHigherPrivilegeTarget(): void
    {
        $client = static::createClient();
        $actor = $this->createUser($client, 'user-manager', [CmsPermission::USERS]);
        $target = $this->createUser($client, 'video-manager', [CmsPermission::VIDEO]);
        $targetId = $target->getId();
        self::assertNotNull($targetId);
        $originalEmail = $target->getEmail();
        $originalName = $target->getDisplayName();
        $originalPermissions = $target->getPermissions();
        $client->loginUser($actor);

        try {
            $client->request('GET', '/admin/users/'.$targetId.'/edit');
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/users/'.$targetId.'/sessions');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/users/'.$targetId.'/edit', [
                'admin_user' => [
                    'displayName' => 'Unauthorized rename',
                    'email' => 'forged-target@example.test',
                    'admin' => '1',
                    'permissions' => [CmsPermission::USERS],
                    'accessRoles' => [],
                    'active' => '1',
                    'lockedUntil' => '',
                    'lockReason' => '',
                    'plainPassword' => ['first' => 'ChangedPassword2026!', 'second' => 'ChangedPassword2026!'],
                ],
            ]);
            self::assertResponseStatusCodeSame(403);

            $this->entityManager($client)->clear();
            $stored = $this->entityManager($client)->find(User::class, $targetId);
            self::assertInstanceOf(User::class, $stored);
            self::assertSame($originalEmail, $stored->getEmail());
            self::assertSame($originalName, $stored->getDisplayName());
            self::assertFalse($stored->isAdmin());
            self::assertTrue($stored->isActive());
            self::assertSame($originalPermissions, $stored->getPermissions());
        } finally {
            $this->entityManager($client)->clear();
            $stored = $this->entityManager($client)->find(User::class, $targetId);
            if ($stored instanceof User) {
                $this->entityManager($client)->remove($stored);
                $this->entityManager($client)->flush();
            }
        }
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('target-privilege-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Target privilege '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
