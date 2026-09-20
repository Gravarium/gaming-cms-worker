<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\CmsPermission;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AccountSecurityHardeningTest extends WebTestCase
{
    public function testAuthenticatedLoginRedirectsAndLogoutRejectsGet(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'login-redirect');
        $client->loginUser($user);

        $client->request('GET', '/login');
        self::assertResponseRedirects('/account');

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);
    }

    public function testPasswordChangeRevokesResetTokenOtherSessionsAndTracksMigratedSession(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'password-migrate', 'Old-Password-42');
        $otherSession = new UserSession($user, 'other-'.bin2hex(random_bytes(16)), '127.0.0.2', 'Other browser');
        $this->em($client)->persist($otherSession);
        [, $resetToken] = $client->getContainer()->get(AccountTokenManager::class)->issue(
            $user,
            AccountToken::PURPOSE_PASSWORD_RESET,
            new \DateInterval('PT1H'),
        );
        $this->em($client)->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/security');
        $oldSessionId = $client->getRequest()->getSession()->getId();
        $oldTracked = $client->getContainer()->get(UserSessionRepository::class)->findBySessionId($oldSessionId);
        self::assertInstanceOf(UserSession::class, $oldTracked);

        $client->submit($crawler->selectButton('Passwort sicher ändern')->form([
            'account_password[currentPassword]' => 'Old-Password-42',
            'account_password[newPassword][first]' => 'New-Password-84',
            'account_password[newPassword][second]' => 'New-Password-84',
        ]));
        self::assertResponseRedirects('/account/security');

        $newSessionId = $client->getRequest()->getSession()->getId();
        self::assertNotSame($oldSessionId, $newSessionId);
        self::assertNull($client->getContainer()->get(AccountTokenManager::class)->resolve($resetToken, AccountToken::PURPOSE_PASSWORD_RESET));
        self::assertTrue($otherSession->isRevoked());
        self::assertTrue($oldTracked->isRevoked());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $current = $client->getContainer()->get(UserSessionRepository::class)->findBySessionId($newSessionId);
        self::assertInstanceOf(UserSession::class, $current);
        self::assertFalse($current->isRevoked());

        $stored = $client->getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($this->hasher($client)->isPasswordValid($stored, 'New-Password-84'));
    }

    public function testOwnSessionRevocationRejectsForeignSessionEvenWithValidCsrf(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'session-owner');
        $other = $this->createUser($client, 'session-other');
        $foreign = new UserSession($other, 'foreign-'.bin2hex(random_bytes(16)), '127.0.0.3', 'Foreign browser');
        $this->em($client)->persist($foreign);
        $this->em($client)->flush();
        $client->loginUser($owner);

        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('revoke-own-session-'.$foreign->getId())
            ->getValue();
        $client->request('POST', '/account/security/sessions/'.$foreign->getId().'/revoke', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($foreign->isRevoked());
    }

    public function testPasswordFormRejectsUnexpectedPrivilegeFields(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'mass-assignment', 'Old-Password-42');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/security');
        $form = $crawler->selectButton('Passwort sicher ändern')->form();
        $values = $form->getPhpValues();
        $values['account_password']['currentPassword'] = 'Old-Password-42';
        $values['account_password']['newPassword']['first'] = 'New-Password-84';
        $values['account_password']['newPassword']['second'] = 'New-Password-84';
        $values['account_password']['permissions'] = [CmsPermission::USERS];

        $client->request('POST', '/account/security', $values);
        self::assertResponseStatusCodeSame(422);

        $stored = $client->getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame([], $stored->getPermissions());
        self::assertTrue($this->hasher($client)->isPasswordValid($stored, 'Old-Password-42'));
    }

    private function createUser(KernelBrowser $client, string $label, string $password = 'Unused-Password-42'): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Account '.$label)
            ->verifyEmail();
        $user->setPassword($this->hasher($client)->hashPassword($user, $password));
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
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
