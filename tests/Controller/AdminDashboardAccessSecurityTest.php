<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminDashboardAccessSecurityTest extends WebTestCase
{
    public function testUserWithoutCmsPermissionsCannotAccessDashboard(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'dashboard-no-permission', []);

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeUser($client, $user);
        }
    }

    public function testPreviouslyAuthenticatedAdministratorLosesDashboardAccessAfterDeactivation(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'dashboard-deactivated', [CmsPermission::SETTINGS], true);

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();

            $user->setActive(false);
            $this->entityManager($client)->flush();

            $client->request('GET', '/admin');
            self::assertResponseRedirects('/login');
        } finally {
            $this->removeUser($client, $user);
        }
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions, bool $admin = false): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Dashboard access '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail()
            ->setAdmin($admin);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function removeUser(KernelBrowser $client, User $user): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                $entityManager->remove($storedUser);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
