<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CredentialRecord;
use App\Entity\User;
use App\Repository\CredentialRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyManagementSecurityTest extends WebTestCase
{
    public function testAnonymousUserCannotDeletePasskey(): void
    {
        $client = static::createClient();
        $client->request('POST', '/account/passkeys/00000000-0000-4000-8000-000000000000/delete', ['_token' => 'invalid']);

        self::assertResponseRedirects('/login');
    }

    public function testMemberCanRenameAndDeleteTheirOwnPasskeyWithCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'owner');
        $credential = $this->createCredential($client, $user, 'Old device');
        $credentialId = $credential->getId();
        $client->loginUser($user);

        $page = $client->request('GET', '/account/passkeys');
        self::assertResponseIsSuccessful();
        $renameToken = $this->formToken($page, $credentialId, 'rename');

        $client->request('POST', '/account/passkeys/'.$credentialId.'/rename', [
            '_token' => $renameToken,
            'name' => '  My laptop  ',
        ]);

        self::assertResponseRedirects('/account/passkeys');
        $renamed = $this->reloadCredential($client, $credentialId);
        self::assertInstanceOf(CredentialRecord::class, $renamed);
        self::assertSame('My laptop', $renamed->getName());

        $page = $client->request('GET', '/account/passkeys');
        self::assertResponseIsSuccessful();
        $deleteToken = $this->formToken($page, $credentialId, 'delete');

        $client->request('POST', '/account/passkeys/'.$credentialId.'/delete', ['_token' => $deleteToken]);

        self::assertResponseRedirects('/account/passkeys');
        self::assertNull($this->reloadCredential($client, $credentialId));
    }

    public function testMemberCannotRenameOrDeleteAnotherUsersPasskey(): void
    {
        $client = static::createClient();
        $attacker = $this->createUser($client, 'attacker');
        $owner = $this->createUser($client, 'other-owner');
        $credential = $this->createCredential($client, $owner, 'Owner device');
        $credentialId = $credential->getId();
        $ownerId = $owner->getId();
        self::assertNotNull($ownerId);
        $client->loginUser($attacker);

        $client->request('POST', '/account/passkeys/'.$credentialId.'/rename', [
            '_token' => 'attacker-token',
            'name' => 'Stolen device',
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/account/passkeys/'.$credentialId.'/delete', ['_token' => 'attacker-token']);
        self::assertResponseStatusCodeSame(404);

        $stored = $this->reloadCredential($client, $credentialId);
        self::assertInstanceOf(CredentialRecord::class, $stored);
        self::assertSame('Owner device', $stored->getName());
        self::assertSame((string) $ownerId, $stored->userHandle);
    }

    public function testMissingOrInvalidCsrfCannotRenameOrDeleteOwnedPasskey(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'csrf');
        $credential = $this->createCredential($client, $user, 'Protected device');
        $credentialId = $credential->getId();
        $client->loginUser($user);

        $client->request('GET', '/account/passkeys');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/account/passkeys/'.$credentialId.'/rename', ['name' => 'Should not persist']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/account/passkeys/'.$credentialId.'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $stored = $this->reloadCredential($client, $credentialId);
        self::assertInstanceOf(CredentialRecord::class, $stored);
        self::assertSame('Protected device', $stored->getName());
    }

    private function createUser(KernelBrowser $client, string $label): User
    {
        $user = (new User())
            ->setEmail('passkey-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Passkey '.$label)
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function createCredential(KernelBrowser $client, User $owner, string $name): CredentialRecord
    {
        $userId = $owner->getId();
        self::assertNotNull($userId);
        $credential = new CredentialRecord(
            publicKeyCredentialId: 'credential-'.bin2hex(random_bytes(12)),
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: 'test-public-key',
            userHandle: (string) $userId,
            counter: 0,
            backupEligible: false,
            backupStatus: false,
            uvInitialized: true,
            name: $name,
        );
        $this->entityManager($client)->persist($credential);
        $this->entityManager($client)->flush();

        return $credential;
    }

    private function formToken(Crawler $page, string $credentialId, string $action): string
    {
        $form = $page->filter('form[action="/account/passkeys/'.$credentialId.'/'.$action.'"]');
        self::assertCount(1, $form);
        $token = $form->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    private function reloadCredential(KernelBrowser $client, string $id): ?CredentialRecord
    {
        $this->entityManager($client)->clear();

        return $client->getContainer()->get(CredentialRecordRepository::class)->find($id);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
