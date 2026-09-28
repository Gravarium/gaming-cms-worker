<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Entity\UserSession;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountSecurityManagementTest extends WebTestCase
{
    private const CURRENT_PASSWORD = 'Account-Current-Password-2025!';
    private const NEW_PASSWORD = 'Account-New-Secure-Password-2026!';

    public function testUnauthenticatedAccessRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/security');

        self::assertResponseRedirects('/login');
    }

    public function testInvalidCsrfAndWrongCurrentPasswordPreserveSecurityState(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $tokens = $container->get(AccountTokenManager::class);
        $account = $this->createAccountState($entityManager, $hasher, $tokens);
        $client->loginUser($account['user']);

        $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/account/security', [
            'account_password' => [
                'currentPassword' => self::CURRENT_PASSWORD,
                'newPassword' => [
                    'first' => self::NEW_PASSWORD,
                    'second' => self::NEW_PASSWORD,
                ],
                '_token' => 'invalid-csrf-token',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertSecurityStateUnchanged($client, $account);

        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Passwort sicher ändern')->form([
            'account_password[currentPassword]' => 'Incorrect-Current-Password-2025!',
            'account_password[newPassword][first]' => self::NEW_PASSWORD,
            'account_password[newPassword][second]' => self::NEW_PASSWORD,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->assertSecurityStateUnchanged($client, $account);
    }

    public function testValidPasswordChangeUpdatesPasswordAndRevokesSessionsAndResetTokens(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $tokens = $container->get(AccountTokenManager::class);
        $account = $this->createAccountState($entityManager, $hasher, $tokens);
        $client->loginUser($account['user']);

        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Passwort sicher ändern')->form([
            'account_password[currentPassword]' => self::CURRENT_PASSWORD,
            'account_password[newPassword][first]' => self::NEW_PASSWORD,
            'account_password[newPassword][second]' => self::NEW_PASSWORD,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/account/security');

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storedUser = $entityManager->find(User::class, $account['userId']);
        $storedSession = $entityManager->find(UserSession::class, $account['sessionId']);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(UserSession::class, $storedSession);

        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $tokens = $client->getContainer()->get(AccountTokenManager::class);
        self::assertNotSame($account['passwordHash'], $storedUser->getPassword());
        self::assertFalse($hasher->isPasswordValid($storedUser, self::CURRENT_PASSWORD));
        self::assertTrue($hasher->isPasswordValid($storedUser, self::NEW_PASSWORD));
        self::assertSame($account['securityVersion'] + 1, $storedUser->getSecurityVersion());
        self::assertTrue($storedSession->isRevoked());
        self::assertNull($tokens->resolve($account['resetToken'], AccountToken::PURPOSE_PASSWORD_RESET));
    }

    /**
     * @return array{
     *     user: User,
     *     userId: int,
     *     sessionId: int,
     *     resetToken: string,
     *     passwordHash: string,
     *     securityVersion: int
     * }
     */
    private function createAccountState(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        AccountTokenManager $tokens,
    ): array {
        $user = (new User())
            ->setEmail('account-security-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Account Security Test');
        $user->setPassword($hasher->hashPassword($user, self::CURRENT_PASSWORD));
        $entityManager->persist($user);
        $entityManager->flush();

        $session = new UserSession(
            $user,
            'other-device-'.bin2hex(random_bytes(16)),
            '203.0.113.10',
            'Account security test browser',
        );
        $entityManager->persist($session);
        [, $resetToken] = $tokens->issue(
            $user,
            AccountToken::PURPOSE_PASSWORD_RESET,
            new \DateInterval('P1D'),
        );
        $entityManager->flush();

        $userId = $user->getId();
        $sessionId = $session->getId();
        if ($userId === null || $sessionId === null) {
            throw new \LogicException('Persisted account fixtures must have identifiers.');
        }

        return [
            'user' => $user,
            'userId' => $userId,
            'sessionId' => $sessionId,
            'resetToken' => $resetToken,
            'passwordHash' => $user->getPassword(),
            'securityVersion' => $user->getSecurityVersion(),
        ];
    }

    /**
     * @param array{
     *     user: User,
     *     userId: int,
     *     sessionId: int,
     *     resetToken: string,
     *     passwordHash: string,
     *     securityVersion: int
     * } $account
     */
    private function assertSecurityStateUnchanged(KernelBrowser $client, array $account): void
    {
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $storedUser = $entityManager->find(User::class, $account['userId']);
        $storedSession = $entityManager->find(UserSession::class, $account['sessionId']);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(UserSession::class, $storedSession);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $tokens = $container->get(AccountTokenManager::class);
        self::assertSame($account['passwordHash'], $storedUser->getPassword());
        self::assertTrue($hasher->isPasswordValid($storedUser, self::CURRENT_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($storedUser, self::NEW_PASSWORD));
        self::assertSame($account['securityVersion'], $storedUser->getSecurityVersion());
        self::assertFalse($storedSession->isRevoked());
        self::assertNotNull($tokens->resolve($account['resetToken'], AccountToken::PURPOSE_PASSWORD_RESET));
    }
}
