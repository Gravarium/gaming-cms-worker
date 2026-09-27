<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SiteSettings;
use App\Entity\User;
use App\Repository\SiteSettingsRepository;
use App\Security\CmsPermission;
use App\Theme\ThemeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminThemePreviewTest extends WebTestCase
{
    public function testSettingsLinksToReadOnlyComparisonOfEveryTheme(): void
    {
        $client = $this->clientWithSettingsPermission();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsRepository = $client->getContainer()->get(SiteSettingsRepository::class);
        $before = $settingsRepository->findOneBy([]);
        $beforeState = $before instanceof SiteSettings ? [$before->getId(), $before->getThemeKey()] : null;

        try {
            $settingsPage = $client->request('GET', '/admin/settings');
            self::assertResponseIsSuccessful();
            self::assertSame(1, $settingsPage->filter('a[href="/admin/settings/theme-preview"]')->count());

            $crawler = $client->request('GET', '/admin/settings/theme-preview');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Cache-Control', 'private, no-store');
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

            $registry = $client->getContainer()->get(ThemeRegistry::class);
            $choices = $registry->choices();
            self::assertSame(count($choices), $crawler->filter('.theme-preview-card')->count());

            foreach ($choices as $label => $key) {
                $definition = $registry->get($key);
                $selector = sprintf('.theme-preview-card.theme-preview-card--%s[data-theme-key="%s"]', $key, $key);
                self::assertSame(1, $crawler->filter($selector)->count());
                self::assertSelectorTextContains($selector.' .theme-preview-card__name', $definition->name);
                self::assertSelectorTextContains($selector.' .theme-preview-card__version', $definition->version);
                self::assertSelectorTextContains($selector, $definition->regions[0]);
                self::assertNotSame('', $label);
            }

            $expectedActiveKey = $registry->get($settingsRepository->current()->getThemeKey())->key;
            self::assertSame(
                1,
                $crawler->filter(sprintf('.theme-preview-card[data-theme-key="%s"][data-active="true"]', $expectedActiveKey))->count(),
            );
            self::assertSame(1, $crawler->filter('.theme-preview-card[data-active="true"]')->count());

            $client->request('POST', '/admin/settings/theme-preview');
            self::assertResponseStatusCodeSame(405);

            $entityManager->clear();
            $after = $settingsRepository->findOneBy([]);
            $afterState = $after instanceof SiteSettings ? [$after->getId(), $after->getThemeKey()] : null;
            self::assertSame($beforeState, $afterState);
        } finally {
            $this->removeLoggedInUser($client);
        }
    }

    public function testThemePreviewRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/settings/theme-preview');

        self::assertResponseRedirects('/login');
    }

    public function testThemePreviewRequiresSettingsPermission(): void
    {
        $client = $this->clientWithSettingsPermission(false);

        try {
            $client->request('GET', '/admin/settings/theme-preview');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->removeLoggedInUser($client);
        }
    }

    private function clientWithSettingsPermission(bool $granted = true): KernelBrowser
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $permissions = $granted
            ? [CmsPermission::ACCESS, CmsPermission::SETTINGS]
            : [CmsPermission::ACCESS];
        $user = (new User())
            ->setEmail('theme-preview-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Theme preview test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();
        $client->loginUser($user);

        return $client;
    }

    private function removeLoggedInUser(KernelBrowser $client): void
    {
        $token = $client->getContainer()->get('security.token_storage')->getToken();
        $user = $token?->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            return;
        }

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storedUser = $entityManager->find(User::class, $user->getId());
        if ($storedUser instanceof User) {
            $entityManager->remove($storedUser);
            $entityManager->flush();
        }
    }
}
