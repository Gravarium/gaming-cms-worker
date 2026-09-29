<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\SiteSettingsRepository;
use App\Theme\Module\ModuleThemeCompositionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GamingModuleDashboardTest extends WebTestCase
{
    public function testGamingPageRendersTheConfiguredModuleCompositionAndNavigationTargets(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/gaming');

        self::assertResponseIsSuccessful();

        $settings = $client->getContainer()->get(SiteSettingsRepository::class)->current();
        $composition = $client->getContainer()->get(ModuleThemeCompositionRegistry::class)->get($settings->getThemeKey());
        self::assertSame(
            $composition->structure,
            $crawler->filter('[data-module-structure]')->attr('data-module-structure'),
        );
        foreach ($composition->navigation as $item) {
            $target = substr($item['href'], 1);
            self::assertSame(1, $crawler->filter('#'.$target)->count());
        }
    }

    public function testAuthenticatedDashboardIsNotCacheable(): void
    {
        $client = $this->clientWithUser();

        try {
            $client->request('GET', '/gaming');
            self::assertResponseIsSuccessful();

            $directives = array_map(
                'trim',
                explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))),
            );
            self::assertContains('private', $directives);
            self::assertContains('no-store', $directives);
        } finally {
            $this->removeLoggedInUser($client);
        }
    }

    private function clientWithUser(): KernelBrowser
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('gaming-dashboard-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Gaming dashboard test')
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
