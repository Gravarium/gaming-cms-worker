<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\AccountTokenRepository;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountRecoveryControllerTest extends WebTestCase
{
    public function testForgotPasswordPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/forgot-password');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Passwort vergessen');
    }

    public function testInvalidResetTokenShowsNeutralError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reset-password/not-a-valid-token');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
    }

    public function testInvalidVerificationTokenShowsNeutralError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify-email/not-a-valid-token');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
    }



    public function testVerificationRequestRequiresCsrfAndCreatesOneActiveToken(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('verification-request-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Verification Request Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();
        $client->loginUser($user);

        $client->request('POST', '/account/security/email/send');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $container->get(AccountTokenRepository::class)->findBy([
            'user' => $user,
            'purpose' => AccountToken::PURPOSE_EMAIL_VERIFICATION,
        ]));

        $crawler = $client->request('GET', '/account/security');
        $token = (string) $crawler->filter('form[action="/account/security/email/send"] input[name="_token"]')->attr('value');
        $client->request('POST', '/account/security/email/send', ['_token' => $token]);
        self::assertResponseRedirects('/account/security');

        self::assertCount(1, $container->get(AccountTokenRepository::class)->findBy([
            'user' => $user,
            'purpose' => AccountToken::PURPOSE_EMAIL_VERIFICATION,
        ]));
    }

    public function testPasswordResetChangesPasswordInvalidatesSessionsAndRejectsTokenReuse(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('reset-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Reset Test')
            ->setPassword('old-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        $oldVersion = $user->getSecurityVersion();
        $session = new UserSession($user, 'reset-session-'.bin2hex(random_bytes(16)), '127.0.0.1', 'Reset test');
        $entityManager->persist($session);

        [, $plainToken] = $container->get(AccountTokenManager::class)->issue(
            $user,
            AccountToken::PURPOSE_PASSWORD_RESET,
            new \DateInterval('PT1H'),
        );
        $entityManager->flush();

        $crawler = $client->request('GET', '/reset-password/'.$plainToken);
        $client->submit($crawler->selectButton('Passwort speichern')->form([
            'reset_password[password][first]' => 'New-Secure-Password-42',
            'reset_password[password][second]' => 'New-Secure-Password-42',
        ]));
        self::assertResponseRedirects('/login');

        $entityManager->clear();
        $stored = $entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame($oldVersion + 1, $stored->getSecurityVersion());

        $client->request('GET', '/reset-password/'.$plainToken);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
    }

    public function testValidVerificationTokenVerifiesUserAndCannotBeReused(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('verification-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Verification Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        [, $plainToken] = $container->get(AccountTokenManager::class)->issue(
            $user,
            AccountToken::PURPOSE_EMAIL_VERIFICATION,
            new \DateInterval('P1D'),
        );
        $entityManager->flush();

        $client->request('GET', '/verify-email/'.$plainToken);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'bestätigt');

        $entityManager->clear();
        $verified = $entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $verified);
        self::assertTrue($verified->isEmailVerified());

        $client->request('GET', '/verify-email/'.$plainToken);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
    }
}
