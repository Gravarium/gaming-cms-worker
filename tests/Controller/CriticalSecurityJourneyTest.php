<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CriticalSecurityJourneyTest extends WebTestCase
{
    public function testRealLoginAndCsrfProtectedLogoutJourney(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'login', 'Correct-Horse-42');

        $crawler = $client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/login', [
            '_username' => $user->getEmail(),
            '_password' => 'Correct-Horse-42',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/account');
        $authenticated = $client->getContainer()->get(UserRepository::class)->findOneBy(['email' => $user->getEmail()]);
        self::assertInstanceOf(User::class, $authenticated);
        self::assertNotNull($authenticated->getLastLoginAt());
        self::assertCount(1, $client->getContainer()->get(UserSessionRepository::class)->activeFor($authenticated));

        $client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful('An invalid logout CSRF token must keep the session authenticated.');

        $logoutToken = $this->csrf($client)->getToken('logout')->getValue();
        $client->request('POST', '/logout', ['_csrf_token' => $logoutToken]);
        self::assertResponseRedirects('/');
        $client->request('GET', '/account/security');
        self::assertResponseRedirects('/login');
    }

    public function testInactiveAndLockedAccountsCannotLogIn(): void
    {
        $client = static::createClient();
        foreach (['inactive', 'locked'] as $state) {
            $user = $this->createUser($client, $state, 'Correct-Horse-42');
            if ($state === 'inactive') {
                $user->setActive(false);
            } else {
                $user->lockUntil(new \DateTimeImmutable('+1 hour'), 'Security journey test');
            }
            $this->entityManager($client)->flush();

            $crawler = $client->request('GET', '/login');
            $client->request('POST', '/login', [
                '_username' => $user->getEmail(),
                '_password' => 'Correct-Horse-42',
                '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            ]);
            self::assertResponseRedirects('/login');
            $client->followRedirect();
            self::assertSelectorExists('.alert');
        }
    }

    public function testGranularPermissionAllowsOnlyItsOwnAdministrationArea(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'content-manager', 'Unused-Password-42');
        $user->setPermissions([CmsPermission::CONTENT]);
        $this->entityManager($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/content');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/storage');
        self::assertResponseStatusCodeSame(403);
    }

    public function testFailedQueueRequiresSettingsPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'queue-permission', 'Unused-Password-42');
        $user->setPermissions([CmsPermission::CONTENT]);
        $this->entityManager($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin/queue');
        self::assertResponseStatusCodeSame(403);

        $user->setPermissions([CmsPermission::SETTINGS]);
        $this->entityManager($client)->flush();
        $client->request('GET', '/admin/queue');
        self::assertResponseIsSuccessful();
    }

    public function testPasswordChangeRejectsWrongCurrentPasswordAndThenInvalidatesOtherSessions(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'password-change', 'Old-Password-42');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/security');
        $client->submit($crawler->selectButton('Passwort sicher ändern')->form([
            'account_password[currentPassword]' => 'Wrong-Password-42',
            'account_password[newPassword][first]' => 'New-Password-84',
            'account_password[newPassword][second]' => 'New-Password-84',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="account_password"]', 'bisherige Passwort');

        $storedUser = $client->getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertTrue($this->hasher($client)->isPasswordValid($storedUser, 'Old-Password-42'));

        $crawler = $client->request('GET', '/account/security');
        $client->submit($crawler->selectButton('Passwort sicher ändern')->form([
            'account_password[currentPassword]' => 'Old-Password-42',
            'account_password[newPassword][first]' => 'New-Password-84',
            'account_password[newPassword][second]' => 'New-Password-84',
        ]));
        self::assertResponseRedirects('/account/security');

        $storedUser = $client->getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertTrue($this->hasher($client)->isPasswordValid($storedUser, 'New-Password-84'));
        self::assertFalse($this->hasher($client)->isPasswordValid($storedUser, 'Old-Password-42'));
    }

    public function testSessionRevocationRejectsMissingCsrfAndValidTokenRevokesSelectedSession(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'session-revoke', 'Unused-Password-42');
        $client->loginUser($user);

        $session = new UserSession($user, 'other-'.bin2hex(random_bytes(16)), '127.0.0.2', 'Other security journey session');
        $this->entityManager($client)->persist($session);
        $this->entityManager($client)->flush();

        $crawler = $client->request('GET', '/account/security');
        $token = (string) $crawler
            ->filter('form[action="/account/security/sessions/'.$session->getId().'/revoke"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/account/security/sessions/'.$session->getId().'/revoke');
        self::assertResponseStatusCodeSame(403);
        $storedSession = $client->getContainer()->get(UserSessionRepository::class)->find($session->getId());
        self::assertNotNull($storedSession);
        self::assertFalse($storedSession->isRevoked());

        $client->request('POST', '/account/security/sessions/'.$session->getId().'/revoke', ['_token' => $token]);
        self::assertResponseRedirects('/account/security');
        $storedSession = $client->getContainer()->get(UserSessionRepository::class)->find($session->getId());
        self::assertNotNull($storedSession);
        self::assertTrue($storedSession->isRevoked());
    }

    public function testSecurityVersionChangeInvalidatesAnExistingBrowserSession(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'security-version', 'Unused-Password-42');
        $client->loginUser($user);
        $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();

        $user->invalidateSessions();
        $this->entityManager($client)->flush();

        $client->request('GET', '/account/security');
        self::assertResponseRedirects('/login');
    }

    private function createUser(KernelBrowser $client, string $label, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Security Test '.$label)
            ->verifyEmail();
        $user->setPassword($this->hasher($client)->hashPassword($user, $plainPassword));
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
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
