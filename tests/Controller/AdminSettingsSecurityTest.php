<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SiteSettings;
use App\Entity\User;
use App\Repository\SiteSettingsRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSettingsSecurityTest extends WebTestCase
{
    public function testSettingsAdministrationRequiresSettingsPermission(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONTENT]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/settings');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeUser($client, $userId);
        }
    }

    public function testMissingAndInvalidCsrfDoNotPersistValidSettingsPayload(): void
    {
        $client = static::createClient();
        $repository = $client->getContainer()->get(SiteSettingsRepository::class);
        $knownSettingsIds = [];
        foreach ($repository->findAll() as $storedSettings) {
            $id = $storedSettings->getId();
            if ($id !== null) {
                $knownSettingsIds[] = $id;
            }
        }
        $original = $repository->current();
        $originalId = $original->getId();
        $originalState = $this->snapshot($original);

        $user = $this->user($client, [CmsPermission::SETTINGS]);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            foreach ([null, 'invalid-site-settings-token'] as $token) {
                $crawler = $client->request('GET', '/admin/settings');
                self::assertResponseIsSuccessful();

                $form = $crawler->selectButton('Einstellungen speichern')->form();
                $payload = $form->getPhpValues();
                if (!isset($payload['site_settings']) || !is_array($payload['site_settings'])) {
                    self::fail('The rendered site settings form did not provide its expected form values.');
                }

                $settingsPayload = array_merge($payload['site_settings'], $this->validSettingsPayload());
                self::assertArrayHasKey('_token', $settingsPayload);

                if ($token === null) {
                    unset($settingsPayload['_token']);
                } else {
                    $settingsPayload['_token'] = $token;
                }
                $payload['site_settings'] = $settingsPayload;

                $client->request('POST', '/admin/settings', $payload);

                self::assertResponseStatusCodeSame(422);
                self::assertSelectorTextContains('#site_settings_error1', 'Der CSRF-Token ist ungültig.');
                $this->assertSettingsUnchanged($client, $originalId, $originalState);
            }
        } finally {
            $this->restoreSettings($client, $knownSettingsIds, $originalId, $originalState);
            $this->removeUser($client, $userId);
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('settings-security-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Settings security test')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validSettingsPayload(): array
    {
        return [
            'siteName' => 'Attempted settings change '.bin2hex(random_bytes(4)),
            'description' => 'Valid CSRF regression payload.',
            'homeTitle' => 'Test home title',
            'homeText' => 'Test home content.',
            'primaryColor' => '#123456',
            'colorScheme' => 'dark',
            'themeKey' => 'nebula',
            'defaultLocale' => 'de',
            'enabledLocales' => ['de'],
            'logoUrl' => '',
            'faviconUrl' => '',
        ];
    }

    /**
     * @return array{
     *     siteName:string,
     *     description:?string,
     *     homeTitle:string,
     *     homeText:string,
     *     primaryColor:string,
     *     colorScheme:string,
     *     logoPath:?string,
     *     faviconPath:?string,
     *     themeKey:string,
     *     defaultLocale:string,
     *     enabledLocales:list<string>
     * }
     */
    private function snapshot(SiteSettings $settings): array
    {
        return [
            'siteName' => $settings->getSiteName(),
            'description' => $settings->getDescription(),
            'homeTitle' => $settings->getHomeTitle(),
            'homeText' => $settings->getHomeText(),
            'primaryColor' => $settings->getPrimaryColor(),
            'colorScheme' => $settings->getColorScheme(),
            'logoPath' => $settings->getLogoPath(),
            'faviconPath' => $settings->getFaviconPath(),
            'themeKey' => $settings->getThemeKey(),
            'defaultLocale' => $settings->getDefaultLocale(),
            'enabledLocales' => $settings->getEnabledLocales(),
        ];
    }

    /**
     * @param array{
     *     siteName:string,
     *     description:?string,
     *     homeTitle:string,
     *     homeText:string,
     *     primaryColor:string,
     *     colorScheme:string,
     *     logoPath:?string,
     *     faviconPath:?string,
     *     themeKey:string,
     *     defaultLocale:string,
     *     enabledLocales:list<string>
     * } $snapshot
     */
    private function applySnapshot(SiteSettings $settings, array $snapshot): void
    {
        $settings
            ->setSiteName($snapshot['siteName'])
            ->setDescription($snapshot['description'])
            ->setHomeTitle($snapshot['homeTitle'])
            ->setHomeText($snapshot['homeText'])
            ->setPrimaryColor($snapshot['primaryColor'])
            ->setColorScheme($snapshot['colorScheme'])
            ->setLogoPath($snapshot['logoPath'])
            ->setFaviconPath($snapshot['faviconPath'])
            ->setThemeKey($snapshot['themeKey'])
            ->setDefaultLocale($snapshot['defaultLocale'])
            ->setEnabledLocales($snapshot['enabledLocales']);
    }

    /**
     * @param array{
     *     siteName:string,
     *     description:?string,
     *     homeTitle:string,
     *     homeText:string,
     *     primaryColor:string,
     *     colorScheme:string,
     *     logoPath:?string,
     *     faviconPath:?string,
     *     themeKey:string,
     *     defaultLocale:string,
     *     enabledLocales:list<string>
     * } $originalState
     */
    private function assertSettingsUnchanged(KernelBrowser $client, ?int $originalId, array $originalState): void
    {
        $this->em($client)->clear();
        $stored = $client->getContainer()->get(SiteSettingsRepository::class)->current();

        self::assertSame($originalId, $stored->getId());
        self::assertSame($originalState, $this->snapshot($stored));
    }

    /**
     * @param list<int> $knownSettingsIds
     * @param array{
     *     siteName:string,
     *     description:?string,
     *     homeTitle:string,
     *     homeText:string,
     *     primaryColor:string,
     *     colorScheme:string,
     *     logoPath:?string,
     *     faviconPath:?string,
     *     themeKey:string,
     *     defaultLocale:string,
     *     enabledLocales:list<string>
     * } $originalState
     */
    private function restoreSettings(KernelBrowser $client, array $knownSettingsIds, ?int $originalId, array $originalState): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        $repository = $client->getContainer()->get(SiteSettingsRepository::class);

        foreach ($repository->findAll() as $settings) {
            $id = $settings->getId();
            if ($id !== null && !in_array($id, $knownSettingsIds, true)) {
                $entityManager->remove($settings);
            } elseif ($id === $originalId) {
                $this->applySnapshot($settings, $originalState);
            }
        }

        $entityManager->flush();
    }

    private function removeUser(KernelBrowser $client, int $userId): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }
        $entityManager->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
