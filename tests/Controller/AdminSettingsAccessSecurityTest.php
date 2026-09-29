<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\SiteSettingsRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSettingsAccessSecurityTest extends WebTestCase
{
    public function testContentManagerCannotViewOrSubmitWebsiteSettings(): void
    {
        $client = static::createClient();
        $settingsRepository = $client->getContainer()->get(SiteSettingsRepository::class);
        $originalSiteName = $settingsRepository->current()->getSiteName();
        $client->loginUser($this->createUser($client, 'settings-limited', [CmsPermission::CONTENT]));

        $client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/settings', [
            'site_settings' => ['siteName' => 'Unauthorized settings overwrite'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame($originalSiteName, $settingsRepository->current()->getSiteName());
    }

    public function testSettingsManagerCanLoadWebsiteSettingsForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($client, 'settings-manager', [CmsPermission::SETTINGS]));

        $client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Website-Einstellungen');
        self::assertSelectorExists('form[name="site_settings"]');
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Settings access '.$label)
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
