<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\AccountTokenRepository;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\CmsPermission;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminUserSecurityTest extends WebTestCase
{
    public function testUserManagerCannotManageFullAdministratorAccount(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'manager', [CmsPermission::USERS]);
        $admin = $this->createUser($client, 'full-admin')->setAdmin(true);
        $this->em($client)->flush();
        $client->loginUser($manager);

        $client->request('GET', '/admin/users/'.$admin->getId().'/edit');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/users/'.$admin->getId().'/sessions');
        self::assertResponseStatusCodeSame(403);

        $token = $this->csrf($client)->getToken('admin-send-verification-'.$admin->getId())->getValue();
        $client->request('POST', '/admin/users/'.$admin->getId().'/send-verification', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $client->getContainer()->get(AccountTokenRepository::class)->count(['user' => $admin]));
    }

    public function testFullAdministratorCanManageAnotherFullAdministrator(): void
    {
        $client = static::createClient();
        $actor = $this->createUser($client, 'actor-admin')->setAdmin(true);
        $target = $this->createUser($client, 'target-admin')->setAdmin(true);
        $this->em($client)->flush();
        $client->loginUser($actor);

        $client->request('GET', '/admin/users/'.$target->getId().'/edit');
        self::assertResponseIsSuccessful();
    }

    public function testOwnPasswordCannotBeChangedThroughAdminFormWithoutCurrentPassword(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'self-password')->setAdmin(true);
        $user->setPassword($this->hasher($client)->hashPassword($user, 'Old-Password-42'));
        $this->em($client)->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/users/'.$user->getId().'/edit');
        $form = $crawler->selectButton('Speichern')->form([
            'admin_user[plainPassword][first]' => 'New-Password-84',
            'admin_user[plainPassword][second]' => 'New-Password-84',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Kontosicherheit');

        $stored = $client->getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($this->hasher($client)->isPasswordValid($stored, 'Old-Password-42'));
        self::assertFalse($this->hasher($client)->isPasswordValid($stored, 'New-Password-84'));
    }

    public function testEmailChangeRevokesOldAccountTokensAndExistingSessions(): void
    {
        $client = static::createClient();
        $actor = $this->createUser($client, 'email-admin')->setAdmin(true);
        $target = $this->createUser($client, 'email-target')->verifyEmail();
        $otherSession = new UserSession($target, 'target-session-'.bin2hex(random_bytes(16)), '127.0.0.2', 'Target browser');
        $this->em($client)->persist($otherSession);

        $tokens = $client->getContainer()->get(AccountTokenManager::class);
        [, $verificationToken] = $tokens->issue($target, AccountToken::PURPOSE_EMAIL_VERIFICATION, new \DateInterval('P1D'));
        [, $resetToken] = $tokens->issue($target, AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT1H'));
        $oldSecurityVersion = $target->getSecurityVersion();
        $this->em($client)->flush();

        $client->loginUser($actor);
        $crawler = $client->request('GET', '/admin/users/'.$target->getId().'/edit');
        $newEmail = 'changed-'.bin2hex(random_bytes(6)).'@example.test';
        $form = $crawler->selectButton('Speichern')->form([
            'admin_user[email]' => $newEmail,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');
        self::assertNull($tokens->resolve($verificationToken, AccountToken::PURPOSE_EMAIL_VERIFICATION));
        self::assertNull($tokens->resolve($resetToken, AccountToken::PURPOSE_PASSWORD_RESET));

        $this->em($client)->clear();
        $stored = $this->em($client)->find(User::class, $target->getId());
        $storedSession = $this->em($client)->find(UserSession::class, $otherSession->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertInstanceOf(UserSession::class, $storedSession);
        self::assertSame($newEmail, $stored->getEmail());
        self::assertFalse($stored->isEmailVerified());
        self::assertSame($oldSecurityVersion + 1, $stored->getSecurityVersion());
        self::assertTrue($storedSession->isRevoked());
    }

    public function testAdminSessionRevocationRejectsSessionOwnedByDifferentTarget(): void
    {
        $client = static::createClient();
        $actor = $this->createUser($client, 'session-admin')->setAdmin(true);
        $first = $this->createUser($client, 'session-first');
        $second = $this->createUser($client, 'session-second');
        $foreignSession = new UserSession($second, 'foreign-session-'.bin2hex(random_bytes(16)), '127.0.0.3', 'Foreign browser');
        $this->em($client)->persist($foreignSession);
        $this->em($client)->flush();
        $client->loginUser($actor);

        $token = $this->csrf($client)->getToken('revoke-user-session-'.$foreignSession->getId())->getValue();
        $client->request('POST', '/admin/users/'.$first->getId().'/sessions/'.$foreignSession->getId().'/revoke', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        $stored = $client->getContainer()->get(UserSessionRepository::class)->find($foreignSession->getId());
        self::assertInstanceOf(UserSession::class, $stored);
        self::assertFalse($stored->isRevoked());
    }

    public function testManipulatedAdminFieldCannotPromoteAUser(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'promotion-manager', [CmsPermission::USERS]);
        $target = $this->createUser($client, 'promotion-target');
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/users/'.$target->getId().'/edit');
        $form = $crawler->selectButton('Speichern')->form();
        $values = $form->getPhpValues();
        $values['admin_user']['admin'] = '1';
        $client->request('POST', '/admin/users/'.$target->getId().'/edit', $values);

        self::assertResponseRedirects('/admin/users');
        $stored = $client->getContainer()->get(UserRepository::class)->find($target->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertFalse($stored->isAdmin());
    }

    public function testUnknownPermissionValueIsRejectedByAdminForm(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'permission-manager', [CmsPermission::USERS]);
        $target = $this->createUser($client, 'permission-target');
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/users/'.$target->getId().'/edit');
        $form = $crawler->selectButton('Speichern')->form();
        $values = $form->getPhpValues();
        $values['admin_user']['permissions'] = ['ROLE_ADMIN'];
        $client->request('POST', '/admin/users/'.$target->getId().'/edit', $values);

        self::assertResponseStatusCodeSame(422);
        $stored = $client->getContainer()->get(UserRepository::class)->find($target->getId());
        self::assertInstanceOf(User::class, $stored);
        self::assertSame([], $stored->getPermissions());
        self::assertFalse($stored->isAdmin());
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('User '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash');
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

    private function csrf(KernelBrowser $client): CsrfTokenManagerInterface
    {
        return $client->getContainer()->get(CsrfTokenManagerInterface::class);
    }
}
