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

final class AdminSettingsLocaleValidationTest extends WebTestCase
{
    public function testUnsupportedLocalePairIsRejectedWithoutPersistingSettings(): void
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

        $user = $this->user($client);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        try {
            $crawler = $client->request('GET', '/admin/settings');
            self::assertResponseIsSuccessful();

            $form = $crawler->selectButton('Einstellungen speichern')->form([
                'site_settings[defaultLocale]' => 'en',
                'site_settings[enabledLocales]' => ['de'],
            ]);
            $client->submit($form);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Die Standardsprache muss auch aktiviert sein.');

            $this->em($client)->clear();
            $stored = $client->getContainer()->get(SiteSettingsRepository::class)->current();
            self::assertSame($originalId, $stored->getId());
            self::assertSame($originalState, $this->snapshot($stored));
        } finally {
            $this->restoreSettings($client, $knownSettingsIds, $originalId, $originalState);
            $this->removeUser($client, $userId);
        }
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('settings-locale-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Settings locale validation test')
            ->setPermissions([CmsPermission::SETTINGS])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
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
