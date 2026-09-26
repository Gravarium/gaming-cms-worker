<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminBackupAccessSecurityTest extends WebTestCase
{
    public function testContentManagerCannotViewBackupInventory(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($client, 'backup-limited', [CmsPermission::CONTENT]));

        $client->request('GET', '/admin/backups');

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectorManagerCanViewSafeBackupInventory(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($client, 'backup-manager', [CmsPermission::CONNECTORS]));

        $client->request('GET', '/admin/backups');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Backup und Wiederherstellung');
        self::assertSelectorTextContains('body', 'Nur bereinigte Bestands- und Prüfdaten werden angezeigt');
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Backup access '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
