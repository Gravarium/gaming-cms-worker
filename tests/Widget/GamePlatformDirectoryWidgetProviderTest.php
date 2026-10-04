<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Module\CmsModuleManager;
use App\Widget\GamePlatformDirectoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class GamePlatformDirectoryWidgetProviderTest extends WebTestCase
{
    public function testPlatformDirectoryWidgetIsRegisteredGatedAndLinksToThePublicDirectory(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the platform widget test.');
        }
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $snapshot = ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled(true);
        $entityManager->flush();

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(GamePlatformDirectoryWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertTrue($registry->available(GamePlatformDirectoryWidgetProvider::KEY));
            self::assertSame('gaming', $definition->module);
            self::assertSame('widget/game_platform_directory.html.twig', $definition->template);

            $markup = $client->getContainer()->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'platform-directory-fixture'],
                'config' => [],
                'data' => $registry->data(GamePlatformDirectoryWidgetProvider::KEY, []),
            ]);
            self::assertStringContainsString('href="/games/platforms"', $markup);

            $state->setEnabled(false);
            $entityManager->flush();
            self::assertFalse($registry->available(GamePlatformDirectoryWidgetProvider::KEY));
            self::assertSame([], $registry->data(GamePlatformDirectoryWidgetProvider::KEY, []));
        } finally {
            $entityManager->clear();
            $current = $entityManager->find(CmsModuleState::class, 'gaming');
            if (!$snapshot['exists']) {
                if ($current instanceof CmsModuleState) {
                    $entityManager->remove($current);
                }
            } elseif ($current instanceof CmsModuleState) {
                $current->setEnabled($snapshot['enabled']);
            }
            $entityManager->flush();
        }
    }
}
