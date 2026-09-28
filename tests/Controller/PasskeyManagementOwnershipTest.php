<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CredentialRecord;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyManagementOwnershipTest extends WebTestCase
{
    public function testOnlyOwnerCanSeeStoredPasskey(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['owner']);

        $client->request('GET', '/account/passkeys');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString($fixture['credentialName'], $content);
    }

    public function testForeignUserCannotSeeRenameOrDeleteAnotherUsersPasskey(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['foreign']);

        $client->request('GET', '/account/passkeys');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString($fixture['credentialName'], $content);

        $renamePath = '/account/passkeys/'.$fixture['credentialId'].'/rename';
        $client->request('POST', $renamePath, ['name' => 'Hijacked passkey']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($fixture['credentialName'], $this->credential($client, $fixture['credentialId'])->getName());

        $deletePath = '/account/passkeys/'.$fixture['credentialId'].'/delete';
        $client->request('POST', $deletePath);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($fixture['credentialName'], $this->credential($client, $fixture['credentialId'])->getName());
    }

    public function testOwnerMutationsRequireTheirRenderedActionTokens(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['owner']);

        $crawler = $client->request('GET', '/account/passkeys');
        self::assertResponseIsSuccessful();

        $renamePath = '/account/passkeys/'.$fixture['credentialId'].'/rename';
        $deletePath = '/account/passkeys/'.$fixture['credentialId'].'/delete';
        $renameToken = $crawler->filter('form[action="'.$renamePath.'"] input[name="_token"]')->attr('value');
        $deleteToken = $crawler->filter('form[action="'.$deletePath.'"] input[name="_token"]')->attr('value');
        self::assertNotNull($renameToken);
        self::assertNotNull($deleteToken);

        $client->request('POST', $renamePath, ['name' => 'Renamed without token']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($fixture['credentialName'], $this->credential($client, $fixture['credentialId'])->getName());

        $client->request('POST', $renamePath, ['_token' => 'forged-passkey-token', 'name' => 'Renamed with forged token']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($fixture['credentialName'], $this->credential($client, $fixture['credentialId'])->getName());

        $client->request('POST', $renamePath, ['_token' => $renameToken, 'name' => 'Renamed by owner']);
        self::assertResponseRedirects('/account/passkeys');
        self::assertSame('Renamed by owner', $this->credential($client, $fixture['credentialId'])->getName());

        $client->request('POST', $deletePath);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Renamed by owner', $this->credential($client, $fixture['credentialId'])->getName());

        $client->request('POST', $deletePath, ['_token' => $renameToken]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Renamed by owner', $this->credential($client, $fixture['credentialId'])->getName());

        $client->request('POST', $deletePath, ['_token' => $deleteToken]);
        self::assertResponseRedirects('/account/passkeys');
        self::assertNull($this->findCredential($client, $fixture['credentialId']));
    }

    /**
     * @return array{owner: User, foreign: User, credentialId: string, credentialName: string}
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $owner = $this->user('passkey-owner-'.$suffix);
        $foreign = $this->user('passkey-foreign-'.$suffix);
        $entityManager->persist($owner);
        $entityManager->persist($foreign);
        $entityManager->flush();

        $ownerId = $owner->getId();
        self::assertNotNull($ownerId);

        $credentialName = 'Owner passkey '.$suffix;
        $credential = new CredentialRecord(
            rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            base64_encode(random_bytes(64)),
            (string) $ownerId,
            0,
            name: $credentialName,
        );
        $entityManager->persist($credential);
        $entityManager->flush();

        return [
            'owner' => $owner,
            'foreign' => $foreign,
            'credentialId' => $credential->getId(),
            'credentialName' => $credentialName,
        ];
    }

    private function user(string $emailPrefix): User
    {
        return (new User())
            ->setEmail($emailPrefix.'@example.test')
            ->setDisplayName('Passkey test user')
            ->verifyEmail();
    }

    private function credential(KernelBrowser $client, string $id): CredentialRecord
    {
        $credential = $this->findCredential($client, $id);
        self::assertInstanceOf(CredentialRecord::class, $credential);

        return $credential;
    }

    private function findCredential(KernelBrowser $client, string $id): ?CredentialRecord
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $credential = $entityManager->find(CredentialRecord::class, $id);

        return $credential instanceof CredentialRecord ? $credential : null;
    }
}
