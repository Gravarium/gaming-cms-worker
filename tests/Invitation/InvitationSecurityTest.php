<?php

declare(strict_types=1);

namespace App\Tests\Invitation;

use App\Entity\AccessRole;
use App\Entity\CmsModuleState;
use App\Entity\Invitation\MemberInvitation;
use App\Entity\User;
use App\Invitation\InvitationRegistrationService;
use App\Invitation\InvitationService;
use App\Repository\Invitation\MemberInvitationRepository;
use App\Repository\UserRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvitationSecurityTest extends WebTestCase
{
    public function testInvitationAdministrationRequiresUserManagementPermission(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $user = $this->user($client, 'no-permission');
        $client->loginUser($user);

        $client->request('GET', '/admin/invitations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDisabledUsersModuleHidesInvitationAdministration(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, 'module-admin')->setAdmin(true);
        $this->em($client)->flush();
        $client->loginUser($admin);
        $this->setUsersModule($client, false);

        try {
            $client->request('GET', '/admin/invitations');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->setUsersModule($client, true);
        }
    }

    public function testIssuedInvitationUsesRenderedCsrfForRevocation(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $admin = $this->user($client, 'issuer')->setAdmin(true);
        $this->em($client)->flush();
        $client->loginUser($admin);
        $email = 'invited-'.bin2hex(random_bytes(5)).'@example.test';

        $crawler = $client->request('GET', '/admin/invitations');
        $form = $crawler->selectButton('Einladung erstellen')->form([
            'invitation_issue[email]' => $email,
            'invitation_issue[accessRole]' => '',
            'invitation_issue[ttlHours]' => '24',
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));

        $href = (string) $crawler->filter('a[href^="/invitation/"]')->attr('href');
        $rawToken = substr($href, strlen('/invitation/'));
        self::assertNotSame('', $rawToken);
        $invitation = $client->getContainer()->get(MemberInvitationRepository::class)->byRawToken($rawToken);
        self::assertInstanceOf(MemberInvitation::class, $invitation);
        self::assertSame(MemberInvitation::STATUS_PENDING, $invitation->getStatus());
        $id = $invitation->getId();
        self::assertNotNull($id);

        $revokeToken = (string) $crawler
            ->filter('form[action="/admin/invitations/'.$id.'/revoke"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/admin/invitations/'.$id.'/revoke');
        self::assertResponseStatusCodeSame(403);
        $pending = $this->em($client)->find(MemberInvitation::class, $id);
        self::assertInstanceOf(MemberInvitation::class, $pending);
        self::assertSame(MemberInvitation::STATUS_PENDING, $pending->getStatus());

        $client->request('POST', '/admin/invitations/'.$id.'/revoke', ['_token' => $revokeToken]);
        self::assertResponseRedirects('/admin/invitations');
        $revoked = $this->em($client)->find(MemberInvitation::class, $id);
        self::assertInstanceOf(MemberInvitation::class, $revoked);
        self::assertSame(MemberInvitation::STATUS_REVOKED, $revoked->getStatus());
    }

    public function testIssuerCannotDelegateRoleAboveOwnPermissionCeiling(): void
    {
        $client = static::createClient();
        $actor = $this->user($client, 'limited')->setPermissions([CmsPermission::USERS]);
        $role = (new AccessRole())
            ->setKey('content-role-'.bin2hex(random_bytes(3)))
            ->setName('Content managers')
            ->setPermissions([CmsPermission::CONTENT]);
        $this->em($client)->persist($role);
        $this->em($client)->flush();

        $this->expectException(\DomainException::class);
        $client->getContainer()->get(InvitationService::class)->issue(
            $actor,
            'ceiling-'.bin2hex(random_bytes(4)).'@example.test',
            $role,
            24,
            new \DateTimeImmutable(),
        );
    }

    public function testInvitationHourlyAbuseLimitFailsClosed(): void
    {
        $client = static::createClient();
        $actor = $this->user($client, 'rate')->setPermissions([CmsPermission::USERS]);
        $this->em($client)->flush();
        $service = $client->getContainer()->get(InvitationService::class);
        $now = new \DateTimeImmutable();

        for ($i = 0; $i < 10; ++$i) {
            $service->issue(
                $actor,
                sprintf('rate-%d-%s@example.test', $i, bin2hex(random_bytes(3))),
                null,
                24,
                $now,
            );
        }

        $this->expectException(\DomainException::class);
        $service->issue(
            $actor,
            'rate-over-'.bin2hex(random_bytes(3)).'@example.test',
            null,
            24,
            $now,
        );
    }

    public function testRolePermissionExpansionInvalidatesPreviouslyIssuedInvitation(): void
    {
        $client = static::createClient();
        $actor = $this->user($client, 'snapshot')->setPermissions([CmsPermission::USERS]);
        $role = (new AccessRole())
            ->setKey('snapshot-role-'.bin2hex(random_bytes(3)))
            ->setName('Snapshot role')
            ->setPermissions([CmsPermission::USERS]);
        $this->em($client)->persist($role);
        $this->em($client)->flush();

        $result = $client->getContainer()->get(InvitationService::class)->issue(
            $actor,
            'snapshot-'.bin2hex(random_bytes(3)).'@example.test',
            $role,
            24,
            new \DateTimeImmutable(),
        );
        self::assertTrue($result->invitation->isUsable(new \DateTimeImmutable()));

        $role->setPermissions([CmsPermission::USERS, CmsPermission::CONTENT]);
        self::assertFalse($result->invitation->isUsable(new \DateTimeImmutable()));
    }

    public function testWeakPasswordIsRejectedWithoutConsumingInvitation(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $issuer = $this->user($client, 'weak-issuer')->setAdmin(true);
        $this->em($client)->flush();
        $result = $client->getContainer()->get(InvitationService::class)->issue(
            $issuer,
            'weak-'.bin2hex(random_bytes(4)).'@example.test',
            null,
            24,
            new \DateTimeImmutable(),
        );

        $crawler = $client->request('GET', '/invitation/'.$result->rawToken);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Konto erstellen')->form([
            'invitation_registration[displayName]' => 'Weak invitee',
            'invitation_registration[password][first]' => 'weak',
            'invitation_registration[password][second]' => 'weak',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $storedInvitation = $client->getContainer()->get(MemberInvitationRepository::class)->byRawToken($result->rawToken);
        self::assertInstanceOf(MemberInvitation::class, $storedInvitation);
        self::assertSame(MemberInvitation::STATUS_PENDING, $storedInvitation->getStatus());
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneBy([
            'email' => $storedInvitation->getEmail(),
        ]));
    }

    public function testInvitationRegistrationIsSingleUseAndCreatesUnverifiedAccount(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $issuer = $this->user($client, 'registration-issuer')->setAdmin(true);
        $this->em($client)->flush();
        $email = 'registration-'.bin2hex(random_bytes(4)).'@example.test';
        $result = $client->getContainer()->get(InvitationService::class)->issue(
            $issuer,
            $email,
            null,
            24,
            new \DateTimeImmutable(),
        );

        $crawler = $client->request('GET', '/invitation/'.$result->rawToken);
        $form = $crawler->selectButton('Konto erstellen')->form([
            'invitation_registration[displayName]' => 'Invited member',
            'invitation_registration[password][first]' => 'StrongPassword123',
            'invitation_registration[password][second]' => 'StrongPassword123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $this->em($client)->clear();
        $created = $client->getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $created);
        self::assertFalse($created->isEmailVerified());
        $storedInvitation = $client->getContainer()->get(MemberInvitationRepository::class)->byRawToken($result->rawToken);
        self::assertInstanceOf(MemberInvitation::class, $storedInvitation);
        self::assertSame(MemberInvitation::STATUS_ACCEPTED, $storedInvitation->getStatus());
        self::assertSame($created->getId(), $storedInvitation->getAcceptedUser()?->getId());

        $client->request('GET', '/invitation/'.$result->rawToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredInvitationDoesNotResolve(): void
    {
        $client = static::createClient();
        $issuer = $this->user($client, 'expired');
        $createdAt = new \DateTimeImmutable('-2 hours');
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $invitation = new MemberInvitation(
            $issuer,
            'expired-'.bin2hex(random_bytes(3)).'@example.test',
            hash('sha256', $raw),
            null,
            [],
            new \DateTimeImmutable('-1 hour'),
            $createdAt,
        );
        $this->em($client)->persist($invitation);
        $this->em($client)->flush();

        self::assertNull($client->getContainer()->get(InvitationRegistrationService::class)->resolve(
            $raw,
            new \DateTimeImmutable(),
        ));
    }

    private function user(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('invitation-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Invitation '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function setUsersModule(KernelBrowser $client, bool $enabled): void
    {
        $state = $this->em($client)->find(CmsModuleState::class, 'users');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('users')->updateVersion('test');
            $this->em($client)->persist($state);
        }
        $state->setEnabled($enabled);
        $this->em($client)->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
