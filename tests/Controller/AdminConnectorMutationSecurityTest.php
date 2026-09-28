<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExternalConnectorTarget;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminConnectorMutationSecurityTest extends WebTestCase
{
    public function testContentManagerCannotViewOrDeleteConnectorTargets(): void
    {
        $client = static::createClient();
        $targetId = $this->createTarget($client, 'denied-delete');
        $client->loginUser($this->createUser($client, 'limited', [CmsPermission::CONTENT]));

        $client->request('GET', '/admin/connectors');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/connectors/'.$targetId.'/delete');
        self::assertResponseStatusCodeSame(403);
        $this->assertTargetExists($client, $targetId);
    }

    public function testConnectorDeletionRequiresCsrfAndRenderedTokenDeletesOnlyTarget(): void
    {
        $client = static::createClient();
        $targetId = $this->createTarget($client, 'authorized-delete');
        $client->loginUser($this->createUser($client, 'connector-manager', [CmsPermission::CONNECTORS]));

        $client->request('POST', '/admin/connectors/'.$targetId.'/delete');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/connectors/'.$targetId.'/delete', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->assertTargetExists($client, $targetId);

        $crawler = $client->request('GET', '/admin/connectors');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler
            ->filter('form[action="/admin/connectors/'.$targetId.'/delete"] input[name="_token"]')
            ->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/admin/connectors/'.$targetId.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/connectors');
        $this->entityManager($client)->clear();
        self::assertNull($this->entityManager($client)->find(ExternalConnectorTarget::class, $targetId));
    }

    private function createTarget(KernelBrowser $client, string $label): int
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('mail-'.$label.'-'.bin2hex(random_bytes(3)))
            ->setProviderKey('test-mail')
            ->setDisplayName('Test mail '.$label)
            ->setEnabled(false)
            ->setRequired(false);
        $this->entityManager($client)->persist($target);
        $this->entityManager($client)->flush();

        $id = $target->getId();
        self::assertNotNull($id);

        return $id;
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Connector mutation '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function assertTargetExists(KernelBrowser $client, int $targetId): void
    {
        $this->entityManager($client)->clear();
        self::assertInstanceOf(
            ExternalConnectorTarget::class,
            $this->entityManager($client)->find(ExternalConnectorTarget::class, $targetId),
        );
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
