<?php

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Entity\CmsModuleState;
use App\Entity\MediaAsset;
use App\Entity\Profile\MemberProfile;
use App\Entity\Profile\ProfileDeletionRequest;
use App\Entity\User;
use App\Profile\ProfileDataRightsService;
use App\Profile\ProfileVisibilityPolicy;
use App\Repository\Profile\MemberProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileSecurityTest extends WebTestCase
{
    public function testProfileEditorRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/profile');

        self::assertResponseRedirects('/login');
    }

    public function testDisabledUsersModuleHidesProfileSurface(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'module');
        $client->loginUser($user);
        $this->setUsersModule($client, false);

        try {
            $client->request('GET', '/account/profile');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->setUsersModule($client, true);
        }
    }

    public function testProfileEditorRejectsWrongModuleMediaAndAcceptsSafeUserImage(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $user = $this->user($client, 'media');
        $wrong = $this->image($client, 'content', 'wrong');
        $safe = $this->image($client, 'users', 'safe');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/profile');
        $form = $crawler->selectButton('Profil speichern')->form();
        $form['member_profile[bio]']->setValue('Private profile bio');
        $form['member_profile[avatarAssetId]']->setValue((string) $wrong->getId());
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'active safe image owned by the users module');
        self::assertNull($client->getContainer()->get(MemberProfileRepository::class)->forUser($user));

        $crawler = $client->request('GET', '/account/profile');
        $form = $crawler->selectButton('Profil speichern')->form();
        $form['member_profile[bio]']->setValue('Safe profile bio');
        $form['member_profile[avatarAssetId]']->setValue((string) $safe->getId());
        $client->submit($form);

        self::assertResponseRedirects('/account/profile');
        $this->em($client)->clear();
        $storedUser = $this->em($client)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        $profile = $client->getContainer()->get(MemberProfileRepository::class)->forUser($storedUser);
        self::assertInstanceOf(MemberProfile::class, $profile);
        self::assertSame($safe->getId(), $profile->getAvatar()?->getId());
    }

    public function testPrivateFieldsAreHiddenFromAnonymousAndVisibleToOwner(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $owner = $this->user($client, 'private');
        $profile = (new MemberProfile($owner))
            ->setBio('Owner-only biography')
            ->setDisplayNameVisibility(MemberProfile::VISIBILITY_PRIVATE)
            ->setBioVisibility(MemberProfile::VISIBILITY_PRIVATE);
        $this->em($client)->persist($profile);
        $this->em($client)->flush();

        $client->request('GET', '/members/'.$owner->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mitglied');
        self::assertSelectorTextNotContains('body', $owner->getDisplayName());
        self::assertSelectorTextNotContains('body', 'Owner-only biography');

        $client->loginUser($owner);
        $client->request('GET', '/members/'.$owner->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $owner->getDisplayName());
        self::assertSelectorTextContains('body', 'Owner-only biography');
    }

    public function testLockedViewerCannotReadMemberOnlyField(): void
    {
        $client = static::createClient();
        $owner = $this->user($client, 'owner');
        $viewer = $this->user($client, 'locked')->lockUntil(new \DateTimeImmutable('+1 hour'), 'test');
        $profile = (new MemberProfile($owner))
            ->setBio('Member biography')
            ->setBioVisibility(MemberProfile::VISIBILITY_MEMBERS);

        $policy = new ProfileVisibilityPolicy();
        self::assertFalse($policy->canView($profile, MemberProfile::FIELD_BIO, null));
        self::assertFalse($policy->canView($profile, MemberProfile::FIELD_BIO, $viewer));
        self::assertTrue($policy->canView($profile, MemberProfile::FIELD_BIO, $owner));
    }

    public function testDeletionRequestRequiresRenderedCsrfAndCanBeCancelled(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $user = $this->user($client, 'delete');
        $client->loginUser($user);

        $client->request('POST', '/account/profile/delete-request');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em($client)->find(ProfileDeletionRequest::class, $user->getId()));

        $crawler = $client->request('GET', '/account/profile/data');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler
            ->filter('form[action="/account/profile/delete-request"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/account/profile/delete-request', ['_token' => $token]);
        self::assertResponseRedirects('/account/profile/data');

        $this->em($client)->clear();
        $request = $this->em($client)->find(ProfileDeletionRequest::class, $user->getId());
        self::assertInstanceOf(ProfileDeletionRequest::class, $request);
        self::assertSame(ProfileDeletionRequest::STATUS_PENDING, $request->getStatus());
        self::assertGreaterThanOrEqual(29, $request->getRequestedAt()->diff($request->getExecuteAfter())->days ?? 0);

        $crawler = $client->request('GET', '/account/profile/data');
        $cancelToken = (string) $crawler
            ->filter('form[action="/account/profile/delete-cancel"] input[name="_token"]')
            ->attr('value');
        $client->request('POST', '/account/profile/delete-cancel', ['_token' => $cancelToken]);
        self::assertResponseRedirects('/account/profile/data');

        $this->em($client)->clear();
        $cancelled = $this->em($client)->find(ProfileDeletionRequest::class, $user->getId());
        self::assertInstanceOf(ProfileDeletionRequest::class, $cancelled);
        self::assertSame(ProfileDeletionRequest::STATUS_CANCELLED, $cancelled->getStatus());
    }

    public function testExportIsPrivateAndDeletionExecutesOnlyAfterRetention(): void
    {
        $client = static::createClient();
        $this->setUsersModule($client, true);
        $user = $this->user($client, 'rights');
        $profile = (new MemberProfile($user))->setBio('Exported biography');
        $this->em($client)->persist($profile);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/account/profile/export.json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($user->getEmail(), $payload['account']['email']);
        self::assertSame('Exported biography', $payload['profile']['bio']);

        $service = $client->getContainer()->get(ProfileDataRightsService::class);
        $now = new \DateTimeImmutable();
        $deletion = $service->requestDeletion($user, $now);
        $this->em($client)->flush();

        $this->expectException(\DomainException::class);
        try {
            $service->execute($deletion, $now->modify('+29 days'));
        } finally {
            self::assertTrue($user->isActive());
        }
    }

    public function testDueDeletionAnonymizesAccountAndRemovesProfile(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'execute');
        $profile = (new MemberProfile($user))->setBio('Must be removed');
        $this->em($client)->persist($profile);
        $this->em($client)->flush();

        $service = $client->getContainer()->get(ProfileDataRightsService::class);
        $now = new \DateTimeImmutable('-31 days');
        $deletion = $service->requestDeletion($user, $now);
        $this->em($client)->flush();

        $service->execute($deletion, new \DateTimeImmutable());
        $this->em($client)->flush();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $this->em($client)->clear();
        $stored = $this->em($client)->find(User::class, $userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertFalse($stored->isActive());
        self::assertStringEndsWith('@example.invalid', $stored->getEmail());
        self::assertSame('Deleted member', $stored->getDisplayName());
        self::assertNull($client->getContainer()->get(MemberProfileRepository::class)->forUser($stored));
        $storedDeletion = $this->em($client)->find(ProfileDeletionRequest::class, $userId);
        self::assertInstanceOf(ProfileDeletionRequest::class, $storedDeletion);
        self::assertSame(ProfileDeletionRequest::STATUS_COMPLETED, $storedDeletion->getStatus());
    }

    private function user(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('profile-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Profile '.$suffix.' '.bin2hex(random_bytes(2)))
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function image(KernelBrowser $client, string $module, string $suffix): MediaAsset
    {
        $asset = (new MediaAsset())
            ->setModuleKey($module)
            ->setStorageMode('internal')
            ->setLocation('/uploads/media/'.$module.'/'.bin2hex(random_bytes(5)).'.png')
            ->setOriginalName($suffix.'.png')
            ->setTitle($suffix)
            ->setMimeType('image/png')
            ->setFileSize(128);
        $this->em($client)->persist($asset);
        $this->em($client)->flush();

        return $asset;
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
