<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountProfileControllerTest extends WebTestCase
{
    public function testAuthenticatedMemberCanReachAndUpdateOwnDisplayName(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'profile-positive');
        $userId = $user->getId();
        self::assertNotNull($userId);
        $email = $user->getEmail();
        $oldDisplayName = $user->getDisplayName();
        $roles = $user->getRoles();
        $permissions = $user->getPermissions();
        $password = $user->getPassword();
        $securityVersion = $user->getSecurityVersion();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/account/profile"]');
        self::assertSelectorExists('a[href="/account/display-name"]');
        $crawler = $client->click($crawler->selectLink('Anzeigename bearbeiten')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="account_profile[displayName]"]');

        $client->submit($crawler->selectButton('Anzeigename speichern')->form([
            'account_profile[displayName]' => 'Neuer Anzeigename',
        ]));
        self::assertResponseRedirects('/account/display-name');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice', 'aktualisiert');

        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $stored = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Neuer Anzeigename', $stored->getDisplayName());
        self::assertSame($email, $stored->getEmail());
        self::assertSame($roles, $stored->getRoles());
        self::assertSame($permissions, $stored->getPermissions());
        self::assertSame($password, $stored->getPassword());
        self::assertSame($securityVersion, $stored->getSecurityVersion());

        $logs = $client->getContainer()->get(AuditLogRepository::class)->findBy([
            'action' => 'account.profile.display_name.changed',
            'subjectId' => $userId,
        ]);
        self::assertCount(1, $logs);
        self::assertInstanceOf(AuditLog::class, $logs[0]);
        self::assertSame('Eigenen Anzeigenamen geändert.', $logs[0]->getSummary());
        self::assertSame([], $logs[0]->getContext());
        self::assertStringNotContainsString('Account profile-positive', $logs[0]->getSummary());
        self::assertStringNotContainsString('Neuer Anzeigename', $logs[0]->getSummary());
        self::assertStringNotContainsString($oldDisplayName, $logs[0]->getSummary());
    }

    public function testDisplayNameEditorRespectsUsersModuleGate(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'profile-module-gate');
        $client->loginUser($user);

        $entityManager = $this->entityManager($client);
        $original = $entityManager->find(CmsModuleState::class, 'users');
        $hadOriginalState = $original instanceof CmsModuleState;
        $wasEnabled = $original?->isEnabled() ?? true;
        $state = $original ?? (new CmsModuleState())->setModuleKey('users')->updateVersion('test');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/account/display-name');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $restoreManager = $this->entityManager($client);
            $current = $restoreManager->find(CmsModuleState::class, 'users');
            if (!$hadOriginalState) {
                if ($current instanceof CmsModuleState) {
                    $restoreManager->remove($current);
                }
            } elseif ($current instanceof CmsModuleState) {
                $current->setEnabled($wasEnabled);
            }
            $restoreManager->flush();
        }
    }

    public function testProfileRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/display-name');

        self::assertResponseRedirects('/login');
    }

    public function testMissingCsrfTokenAndOverlongDisplayNameAreRejected(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'profile-validation');
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/display-name');
        $values = $crawler->selectButton('Anzeigename speichern')->form()->getPhpValues();
        unset($values['account_profile']['_token']);
        $client->request('POST', '/account/display-name', $values);
        self::assertResponseStatusCodeSame(422);
        $this->assertStoredDisplayName($client, $userId, 'Account profile-validation');

        $crawler = $client->request('GET', '/account/display-name');
        $values = $crawler->selectButton('Anzeigename speichern')->form()->getPhpValues();
        $values['account_profile']['displayName'] = str_repeat('x', 81);
        $client->request('POST', '/account/display-name', $values);
        self::assertResponseStatusCodeSame(422);
        $this->assertStoredDisplayName($client, $userId, 'Account profile-validation');

        $crawler = $client->request('GET', '/account/display-name');
        $values = $crawler->selectButton('Anzeigename speichern')->form()->getPhpValues();
        $values['account_profile']['displayName'] = '   ';
        $client->request('POST', '/account/display-name', $values);
        self::assertResponseStatusCodeSame(422);
        $this->assertStoredDisplayName($client, $userId, 'Account profile-validation');
        self::assertCount(0, $client->getContainer()->get(AuditLogRepository::class)->findBy([
            'action' => 'account.profile.display_name.changed',
            'subjectId' => $userId,
        ]));
    }

    public function testForgedEmailRoleAndPermissionFieldsAreRejected(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'profile-forged-fields');
        $userId = $user->getId();
        self::assertNotNull($userId);
        $email = $user->getEmail();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/display-name');
        $values = $crawler->selectButton('Anzeigename speichern')->form()->getPhpValues();
        $values['account_profile']['displayName'] = 'Forged Name';
        $values['account_profile']['email'] = 'attacker@example.test';
        $values['account_profile']['roles'] = ['ROLE_ADMIN'];
        $values['account_profile']['permissions'] = ['cms.users.manage'];

        $client->request('POST', '/account/display-name', $values);

        self::assertResponseStatusCodeSame(422);
        $stored = $this->assertStoredDisplayName($client, $userId, 'Account profile-forged-fields');
        self::assertSame($email, $stored->getEmail());
        self::assertSame(['ROLE_USER'], $stored->getRoles());
        self::assertSame([], $stored->getPermissions());
        self::assertFalse($stored->isAdmin());
        self::assertCount(0, $client->getContainer()->get(AuditLogRepository::class)->findBy([
            'action' => 'account.profile.display_name.changed',
            'subjectId' => $userId,
        ]));
    }

    private function createUser(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail($suffix.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Account '.$suffix)
            ->verifyEmail();
        $user->setPassword($client->getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Initial-Password-42'));

        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function assertStoredDisplayName(KernelBrowser $client, int $userId, string $displayName): User
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $stored = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame($displayName, $stored->getDisplayName());

        return $stored;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
