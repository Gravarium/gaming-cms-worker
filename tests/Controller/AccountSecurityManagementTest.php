<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Entity\UserSession;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;
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
        $this->assertSecurityStateUnchanged($entityManager, $hasher, $tokens, $account);

        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Passwort sicher ändern')->form([
            'account_password[currentPassword]' => 'Incorrect-Current-Password-2025!',
            'account_password[newPassword][first]' => self::NEW_PASSWORD,
            'account_password[newPassword][second]' => self::NEW_PASSWORD,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->assertSecurityStateUnchanged($entityManager, $hasher, $tokens, $account);
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

        $entityManager->refresh($account['user']);
        $entityManager->refresh($account['session']);

        self::assertNotSame($account['passwordHash'], $account['user']->getPassword());
        self::assertFalse($hasher->isPasswordValid($account['user'], self::CURRENT_PASSWORD));
        self::assertTrue($hasher->isPasswordValid($account['user'], self::NEW_PASSWORD));
        self::assertSame($account['securityVersion'] + 1, $account['user']->getSecurityVersion());
        self::assertTrue($account['session']->isRevoked());
        self::assertNull($tokens->resolve($account['resetToken'], AccountToken::PURPOSE_PASSWORD_RESET));
    }

    /**
     * @return array{
     *     user: User,
     *     session: UserSession,
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

        return [
            'user' => $user,
            'session' => $session,
            'resetToken' => $resetToken,
            'passwordHash' => $user->getPassword(),
            'securityVersion' => $user->getSecurityVersion(),
        ];
    }

    /**
     * @param array{
     *     user: User,
     *     session: UserSession,
     *     resetToken: string,
     *     passwordHash: string,
     *     securityVersion: int
     * } $account
     */
    private function assertSecurityStateUnchanged(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        AccountTokenManager $tokens,
        array $account,
    ): void {
        $entityManager->refresh($account['user']);
        $entityManager->refresh($account['session']);

        self::assertSame($account['passwordHash'], $account['user']->getPassword());
        self::assertTrue($hasher->isPasswordValid($account['user'], self::CURRENT_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($account['user'], self::NEW_PASSWORD));
        self::assertSame($account['securityVersion'], $account['user']->getSecurityVersion());
        self::assertFalse($account['session']->isRevoked());
        self::assertNotNull($tokens->resolve($account['resetToken'], AccountToken::PURPOSE_PASSWORD_RESET));
    }
}
