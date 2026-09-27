<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\VideoSearchWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class VideoSearchWidgetProviderTest extends WebTestCase
{
    public function testVideoSearchWidgetAppearsInThePageBuilderAndUsesItsFixedRoute(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableVideoModule($client);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('video-search-widget-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video search widget test')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Persisted editor fixture has no database ID.');
        }
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $availableKeys = array_map(
                static fn (WidgetDefinition $definition): string => $definition->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(VideoSearchWidgetProvider::KEY, $availableKeys);

            $definition = $registry->get(VideoSearchWidgetProvider::KEY);
            self::assertNotNull($definition);
            self::assertSame('video', $definition->module);
            self::assertSame('widget/video_search.html.twig', $definition->template);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'search-fixture'],
                'config' => [],
                'data' => $registry->data(VideoSearchWidgetProvider::KEY, []),
            ]);
            self::assertStringContainsString('action="/video-search"', $markup);
            self::assertStringContainsString('method="get"', $markup);
            self::assertStringContainsString('name="q"', $markup);
            self::assertStringContainsString('for="video-search-search-fixture"', $markup);
            self::assertStringContainsString('id="video-search-search-fixture"', $markup);
        } finally {
            $this->removeUserFixture($client, $userId);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testDisabledVideoModuleRemovesSearchFromTheWidgetPaletteAndSuppressesData(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableVideoModule($client);
        try {
            $client->getContainer()->get(CmsModuleManager::class)->setEnabled('video', false);
            $registry = $client->getContainer()->get(WidgetRegistry::class);

            self::assertFalse($registry->available(VideoSearchWidgetProvider::KEY));
            self::assertSame([], $registry->data(VideoSearchWidgetProvider::KEY, []));
            $availableKeys = array_map(
                static fn (WidgetDefinition $definition): string => $definition->key,
                $registry->availableDefinitions(),
            );
            self::assertNotContains(VideoSearchWidgetProvider::KEY, $availableKeys);
        } finally {
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{
     *     mediaExists: bool,
     *     mediaEnabled: bool,
     *     videoExists: bool,
     *     videoEnabled: bool
     * }
     */
    private function enableVideoModule(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $mediaState = $entityManager->find(CmsModuleState::class, 'media');
        $videoState = $entityManager->find(CmsModuleState::class, 'video');
        $snapshot = [
            'mediaExists' => $mediaState !== null,
            'mediaEnabled' => $mediaState?->isEnabled() ?? true,
            'videoExists' => $videoState !== null,
            'videoEnabled' => $videoState?->isEnabled() ?? true,
        ];

        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('media') || !$modules->isInstalled('video')) {
            self::markTestSkipped('The Video and Media modules must be installed for the page-builder widget test.');
        }
        if (!$modules->isEnabled('media')) {
            $modules->setEnabled('media', true);
        }
        if (!$modules->isEnabled('video')) {
            $modules->setEnabled('video', true);
        }

        return $snapshot;
    }

    /**
     * @param array{
     *     mediaExists: bool,
     *     mediaEnabled: bool,
     *     videoExists: bool,
     *     videoEnabled: bool
     * } $snapshot
     */
    private function restoreModuleStates(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        foreach ([
            'media' => ['exists' => $snapshot['mediaExists'], 'enabled' => $snapshot['mediaEnabled']],
            'video' => ['exists' => $snapshot['videoExists'], 'enabled' => $snapshot['videoEnabled']],
        ] as $key => $original) {
            $state = $entityManager->find(CmsModuleState::class, $key);
            if (!$original['exists']) {
                if ($state !== null) {
                    $entityManager->remove($state);
                }
                continue;
            }

            if ($state !== null && $state->isEnabled() !== $original['enabled']) {
                $state->setEnabled($original['enabled']);
            }
        }

        $entityManager->flush();
    }

    private function removeUserFixture(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $userId): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $user = $entityManager->find(User::class, $userId);
        if ($user !== null) {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }
}
