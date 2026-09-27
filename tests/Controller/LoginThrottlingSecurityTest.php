<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginThrottlingSecurityTest extends WebTestCase
{
    public function testRepeatedInvalidPasswordsReachTheConfiguredLoginThrottle(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        $userId = $user->getId();
        $email = $user->getEmail();
        self::assertNotNull($userId);

        try {
            $blocked = false;
            for ($attempt = 0; $attempt < 6; ++$attempt) {
                $crawler = $client->request('GET', '/login');
                self::assertResponseIsSuccessful();

                $form = $crawler->selectButton('Anmelden')->form([
                    '_username' => $email,
                    '_password' => 'Wrong-Password-42',
                ]);
                $client->submit($form);

                if ($client->getResponse()->getStatusCode() === 429) {
                    $blocked = true;
                    break;
                }

                self::assertResponseRedirects('/login');
            }

            self::assertTrue($blocked, 'Repeated invalid credentials should reach the configured login throttle.');
        } finally {
            $this->removeUser($client, $userId);
        }
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('login-throttle-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Login throttling test')
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $user->setPassword($this->hasher($client)->hashPassword($user, 'Correct-Password-84'));
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function removeUser(KernelBrowser $client, int $userId): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }
        $entityManager->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function hasher(KernelBrowser $client): UserPasswordHasherInterface
    {
        return $client->getContainer()->get(UserPasswordHasherInterface::class);
    }
}
