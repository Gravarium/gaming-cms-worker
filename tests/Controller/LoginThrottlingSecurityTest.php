<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginThrottlingSecurityTest extends WebTestCase
{
    public function testRepeatedInvalidPasswordsReachTheConfiguredLoginThrottle(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));
        $user = $this->user($client);
        $userId = $user->getId();
        $email = $user->getEmail();
        self::assertNotNull($userId);

        try {
            $throttled = false;
            for ($attempt = 0; $attempt < 6; ++$attempt) {
                $crawler = $client->request('GET', '/login');
                self::assertResponseIsSuccessful();

                $form = $crawler->selectButton('Anmelden')->form([
                    '_username' => $email,
                    '_password' => 'Wrong-Password-42',
                ]);
                $client->submit($form);
                self::assertResponseRedirects('/login');

                $session = $client->getSession();
                self::assertNotNull($session);
                $authenticationError = $session->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);

                if ($authenticationError instanceof TooManyLoginAttemptsAuthenticationException) {
                    $throttled = true;
                    break;
                }

                self::assertInstanceOf(AuthenticationException::class, $authenticationError);
                $client->followRedirect();
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('.alert', 'E-Mail-Adresse oder Passwort ist falsch.');
            }

            self::assertTrue($throttled, 'The configured login throttle should reject attempts beyond its threshold.');

            $client->request('GET', '/account');
            self::assertResponseRedirects('/login');
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
