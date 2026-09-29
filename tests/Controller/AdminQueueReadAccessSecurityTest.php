<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminQueueReadAccessSecurityTest extends WebTestCase
{
    public function testUserWithoutSettingsPermissionCannotReadFailedMessageQueue(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client);

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/queue');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeUser($client, $user);
        }
    }

    private function createUser(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('queue-read-denied-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Queue read access denied')
            ->setPermissions([CmsPermission::CONTENT])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
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