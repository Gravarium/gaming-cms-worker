<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\SiteSettings;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSettingsWriteSecurityTest extends WebTestCase
{
    public function testUserWithoutSettingsPermissionCannotReadOrWriteSettings(): void
    {
        $client = static::createClient();
        $fixture = $this->prepareSettings($client);
        $user = $this->createUser($client, 'settings-denied', [CmsPermission::CONTENT]);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/settings');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/settings', [
                'site_settings' => ['siteName' => 'Unauthorized settings change'],
            ]);
            self::assertResponseStatusCodeSame(403);

            $this->assertStoredSiteName($client, $fixture['settings_id'], $fixture['snapshot']['site_name']);
        } finally {
            $this->cleanup($client, $user, $fixture);
        }
    }

    public function testMissingAndInvalidFormCsrfDoNotPersistSubmittedSettings(): void
    {
        $client = static::createClient();
        $fixture = $this->prepareSettings($client);
        $user = $this->createUser($client, 'settings-csrf', [CmsPermission::SETTINGS]);

        try {
            $client->loginUser($user);

            foreach ([
                ['remove_token' => true, 'override_token' => null, 'suffix' => 'missing'],
                ['remove_token' => false, 'override_token' => 'invalid', 'suffix' => 'invalid'],
            ] as $case) {
                $forgedName = 'Unstored '.$case['suffix'].' '.bin2hex(random_bytes(4));
                $values = $this->renderedFormValues(
                    $client,
                    $forgedName,
                    $case['remove_token'],
                    $case['override_token'],
                );

                $client->request('POST', '/admin/settings', $values);

                self::assertResponseStatusCodeSame(422);
                $this->assertStoredSiteName($client, $fixture['settings_id'], $fixture['snapshot']['site_name']);
            }
        } finally {
            $this->cleanup($client, $user, $fixture);
        }
    }

    public function testRenderedFormCsrfTokenAllowsAuthorizedSettingsUpdate(): void
    {
        $client = static::createClient();
        $fixture = $this->prepareSettings($client);
        $user = $this->createUser($client, 'settings-valid', [CmsPermission::SETTINGS]);
        $updatedName = 'Authorized update '.bin2hex(random_bytes(4));

        try {
            $client->loginUser($user);
            $values = $this->renderedFormValues($client, $updatedName);

            $client->request('POST', '/admin/settings', $values);

            self::assertResponseRedirects('/admin/settings');
            $this->assertStoredSiteName($client, $fixture['settings_id'], $updatedName);
        } finally {
            $this->cleanup($client, $user, $fixture);
        }
    }

    /**
     * @return array{
     *     settings_id: int,
     *     created: bool,
     *     snapshot: array{
     *         site_name: string,
     *         description: ?string,
     *         home_title: string,
     *         home_text: string,
     *         primary_color: string,
     *         color_scheme: string,
     *         logo_path: ?string,
     *         favicon_path: ?string,
     *         gaming_enabled: bool,
     *         video_enabled: bool,
     *         theme_key: string,
     *         default_locale: string,
     *         enabled_locales: list<string>
     *     }
     * }
     */
    private function prepareSettings(KernelBrowser $client): array
    {
        $entityManager = $this->entityManager($client);
        $existing = $entityManager->getRepository(SiteSettings::class)->findOneBy([]);
        $created = !($existing instanceof SiteSettings);
        $settings = $existing instanceof SiteSettings ? $existing : new SiteSettings();
        $snapshot = $this->snapshot($settings);

        if ($created) {
            $entityManager->persist($settings);
            $entityManager->flush();
        }

        $id = $settings->getId();
        if ($id === null) {
            throw new \LogicException('Site settings must have an identifier for cleanup.');
        }

        return ['settings_id' => $id, 'created' => $created, 'snapshot' => $snapshot];
    }

    /**
     * @return array{
     *     site_name: string,
     *     description: ?string,
     *     home_title: string,
     *     home_text: string,
     *     primary_color: string,
     *     color_scheme: string,
     *     logo_path: ?string,
     *     favicon_path: ?string,
     *     gaming_enabled: bool,
     *     video_enabled: bool,
     *     theme_key: string,
     *     default_locale: string,
     *     enabled_locales: list<string>
     * }
     */
    private function snapshot(SiteSettings $settings): array
    {
        return [
            'site_name' => $settings->getSiteName(),
            'description' => $settings->getDescription(),
            'home_title' => $settings->getHomeTitle(),
            'home_text' => $settings->getHomeText(),
            'primary_color' => $settings->getPrimaryColor(),
            'color_scheme' => $settings->getColorScheme(),
            'logo_path' => $settings->getLogoPath(),
            'favicon_path' => $settings->getFaviconPath(),
            'gaming_enabled' => $settings->isGamingEnabled(),
            'video_enabled' => $settings->isVideoEnabled(),
            'theme_key' => $settings->getThemeKey(),
            'default_locale' => $settings->getDefaultLocale(),
            'enabled_locales' => $settings->getEnabledLocales(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedFormValues(
        KernelBrowser $client,
        string $siteName,
        bool $removeToken = false,
        ?string $tokenOverride = null,
    ): array {
        $crawler = $client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Einstellungen speichern')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);

        $formValues['siteName'] = $siteName;
        if ($removeToken) {
            unset($formValues['_token']);
        } elseif ($tokenOverride !== null) {
            $formValues['_token'] = $tokenOverride;
        }

        $values[$formName] = $formValues;

        return $values;
    }

    private function assertStoredSiteName(KernelBrowser $client, int $settingsId, string $expected): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $settings = $entityManager->find(SiteSettings::class, $settingsId);
        self::assertInstanceOf(SiteSettings::class, $settings);
        self::assertSame($expected, $settings->getSiteName());
    }

    /**
     * @param array{
     *     settings_id: int,
     *     created: bool,
     *     snapshot: array{
     *         site_name: string,
     *         description: ?string,
     *         home_title: string,
     *         home_text: string,
     *         primary_color: string,
     *         color_scheme: string,
     *         logo_path: ?string,
     *         favicon_path: ?string,
     *         gaming_enabled: bool,
     *         video_enabled: bool,
     *         theme_key: string,
     *         default_locale: string,
     *         enabled_locales: list<string>
     *     }
     * } $fixture
     */
    private function cleanup(KernelBrowser $client, User $user, array $fixture): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        $settings = $entityManager->find(SiteSettings::class, $fixture['settings_id']);
        if ($settings instanceof SiteSettings) {
            if ($fixture['created']) {
                $entityManager->remove($settings);
            } else {
                $snapshot = $fixture['snapshot'];
                $settings
                    ->setSiteName($snapshot['site_name'])
                    ->setDescription($snapshot['description'])
                    ->setHomeTitle($snapshot['home_title'])
                    ->setHomeText($snapshot['home_text'])
                    ->setPrimaryColor($snapshot['primary_color'])
                    ->setColorScheme($snapshot['color_scheme'])
                    ->setLogoPath($snapshot['logo_path'])
                    ->setFaviconPath($snapshot['favicon_path'])
                    ->setGamingEnabled($snapshot['gaming_enabled'])
                    ->setVideoEnabled($snapshot['video_enabled'])
                    ->setThemeKey($snapshot['theme_key'])
                    ->setDefaultLocale($snapshot['default_locale'])
                    ->setEnabledLocales($snapshot['enabled_locales']);
            }
        }

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]) as $entry) {
                    $entityManager->remove($entry);
                }
                $entityManager->remove($storedUser);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('settings-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Settings security '.$label)
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
