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

    public function testExpiredAndWrongPurposeResetTokensAreRejected(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('expired-reset-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Expired Reset Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        $manager = $client->getContainer()->get(AccountTokenManager::class);
        [$expired, $expiredPlain] = $manager->issue($user, AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT1H'));
        $expired->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $entityManager->flush();

        $client->request('GET', '/reset-password/'.$expiredPlain);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');

        [, $verificationPlain] = $manager->issue($user, AccountToken::PURPOSE_EMAIL_VERIFICATION, new \DateInterval('P1D'));
        $entityManager->flush();
        $client->request('GET', '/reset-password/'.$verificationPlain);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
    }

    public function testResetTokenBecomesInvalidWhenAccountIsDeactivated(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('inactive-reset-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Inactive Reset Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        [, $plainToken] = $client->getContainer()->get(AccountTokenManager::class)->issue(
            $user,
            AccountToken::PURPOSE_PASSWORD_RESET,
            new \DateInterval('PT1H'),
        );
        $user->setActive(false);
        $entityManager->flush();

        $client->request('GET', '/reset-password/'.$plainToken);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
    }

    public function testIssuingNewResetTokenRevokesPreviousToken(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('rotate-reset-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Rotate Reset Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        $manager = $client->getContainer()->get(AccountTokenManager::class);
        [, $first] = $manager->issue($user, AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT1H'));
        $entityManager->flush();
        [, $second] = $manager->issue($user, AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT1H'));
        $entityManager->flush();

        self::assertNull($manager->resolve($first, AccountToken::PURPOSE_PASSWORD_RESET));
        self::assertNotNull($manager->resolve($second, AccountToken::PURPOSE_PASSWORD_RESET));
    }

    public function testForgotPasswordRateLimitsNormalizedEmailAcrossDifferentClientIps(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $email = 'recovery-email-limit-'.bin2hex(random_bytes(6)).'@example.test';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Recovery Email Limit Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        $ipStart = random_int(1, 240);
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $client->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', $ipStart + $attempt));
            $crawler = $client->request('GET', '/forgot-password');
            $submittedEmail = $attempt % 2 === 0 ? $email : mb_strtoupper($email);
            $client->submit($crawler->selectButton('Link anfordern')->form([
                'forgot_password[email]' => $submittedEmail,
            ]));

            self::assertResponseRedirects('/forgot-password');
        }

        $tokenCount = $client->getContainer()->get(AccountTokenRepository::class)->count([
            'user' => $user,
            'purpose' => AccountToken::PURPOSE_PASSWORD_RESET,
        ]);
        self::assertSame(5, $tokenCount);
    }

    public function testForgotPasswordPerIpRateLimitStillAppliesAcrossAccounts(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $users = [];
        for ($index = 0; $index < 6; ++$index) {
            $user = (new User())
                ->setEmail('recovery-ip-limit-'.$index.'-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Recovery IP Limit Test '.$index)
                ->setPassword('not-used-in-this-test');
            $entityManager->persist($user);
            $users[] = $user;
        }
        $entityManager->flush();

        $client->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 240)));
        foreach ($users as $user) {
            $crawler = $client->request('GET', '/forgot-password');
            $client->submit($crawler->selectButton('Link anfordern')->form([
                'forgot_password[email]' => $user->getEmail(),
            ]));

            self::assertResponseRedirects('/forgot-password');
        }

        $tokenRepository = $client->getContainer()->get(AccountTokenRepository::class);
        foreach ($users as $index => $user) {
            self::assertSame(
                $index < 5 ? 1 : 0,
                $tokenRepository->count([
                    'user' => $user,
                    'purpose' => AccountToken::PURPOSE_PASSWORD_RESET,
                ]),
            );
        }
    }

    public function testForgotPasswordResponseDoesNotEnumerateAccountState(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $active = (new User())
            ->setEmail('known-active-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Known Active')
            ->setPassword('not-used-in-this-test');
        $inactive = (new User())
            ->setEmail('known-inactive-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Known Inactive')
            ->setPassword('not-used-in-this-test')
            ->setActive(false);
        $entityManager->persist($active);
        $entityManager->persist($inactive);
        $entityManager->flush();

        $messages = [];
        foreach ([$active->getEmail(), $inactive->getEmail(), 'missing-'.bin2hex(random_bytes(6)).'@example.test'] as $email) {
            $crawler = $client->request('GET', '/forgot-password');
            $client->submit($crawler->selectButton('Link anfordern')->form(['forgot_password[email]' => $email]));
            self::assertResponseRedirects('/forgot-password');
            $crawler = $client->followRedirect();
            $messages[] = trim($crawler->filter('.notice')->text());
        }

        self::assertCount(1, array_unique($messages));
        self::assertStringContainsString('Wenn ein aktives Konto', $messages[0]);
    }

    public function testTamperedResetTokenDoesNotConsumeOriginalToken(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('tampered-reset-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Tampered Reset Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        $manager = $client->getContainer()->get(AccountTokenManager::class);
        [, $plainToken] = $manager->issue($user, AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT1H'));
        $entityManager->flush();
        $last = substr($plainToken, -1);
        $tampered = substr($plainToken, 0, -1).($last === 'A' ? 'B' : 'A');

        $client->request('GET', '/reset-password/'.$tampered);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'ungültig');
        self::assertNotNull($manager->resolve($plainToken, AccountToken::PURPOSE_PASSWORD_RESET));
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
        $storedSession = $entityManager->find(UserSession::class, $session->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertInstanceOf(UserSession::class, $storedSession);
        self::assertSame($oldVersion + 1, $stored->getSecurityVersion());
        self::assertTrue($storedSession->isRevoked());

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
