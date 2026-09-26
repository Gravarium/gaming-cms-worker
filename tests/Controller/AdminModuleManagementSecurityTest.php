<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminModuleManagementSecurityTest extends WebTestCase
{
    public function testModuleManagerRequiresSettingsPermissionForListingAndToggle(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'reader', [CmsPermission::ACCESS]);
        $before = $client->getContainer()->get(CmsModuleManager::class)->isEnabled('notifications');
        $client->loginUser($user);

        $client->request('GET', '/admin/modules');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/modules/notifications/toggle', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(
            $before,
            $client->getContainer()->get(CmsModuleManager::class)->isEnabled('notifications'),
        );
    }

    public function testSettingsManagerCanToggleNotificationsWithRenderedToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'settings-manager', [CmsPermission::ACCESS, CmsPermission::SETTINGS]);
        $manager = $client->getContainer()->get(CmsModuleManager::class);
        self::assertTrue($manager->isInstalled('notifications'));
        $before = $manager->isEnabled('notifications');
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/admin/modules');
            self::assertResponseIsSuccessful();
            $tokenField = $crawler->filter('form[action="/admin/modules/notifications/toggle"] input[name="_token"]');
            self::assertCount(1, $tokenField);
            $token = (string) $tokenField->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/modules/notifications/toggle', ['_token' => $token]);

            self::assertResponseRedirects('/admin/modules');
            self::assertSame(
                !$before,
                $client->getContainer()->get(CmsModuleManager::class)->isEnabled('notifications'),
            );
        } finally {
            $current = $client->getContainer()->get(CmsModuleManager::class);
            if ($current->isEnabled('notifications') !== $before) {
                $current->setEnabled('notifications', $before);
            }
        }

        self::assertSame(
            $before,
            $client->getContainer()->get(CmsModuleManager::class)->isEnabled('notifications'),
        );
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('module-'.$label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Module '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
