<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\PublicGameCatalogueSearchWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicGameCatalogueSearchWidgetProviderTest extends WebTestCase
{
    public function testSearchWidgetAppearsInThePageBuilderAndUsesItsFixedInternalRoute(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $user = (new User())
            ->setEmail('game-search-widget-'.$token.'@example.test')
            ->setDisplayName('Game search widget '.$token)
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Persisted editor fixture has no database ID.');
        }

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/layout/home');

            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $availableKeys = array_map(
                static fn (WidgetDefinition $definition): string => $definition->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(PublicGameCatalogueSearchWidgetProvider::KEY, $availableKeys);

            $definition = $registry->get(PublicGameCatalogueSearchWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertSame('widget/game_catalogue_search.html.twig', $definition->template);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'search-fixture'],
                'config' => [],
                'data' => $registry->data(PublicGameCatalogueSearchWidgetProvider::KEY, []),
            ]);
            self::assertStringContainsString('action="/game-search"', $markup);
            self::assertStringContainsString('method="get"', $markup);
            self::assertStringContainsString('name="q"', $markup);
            self::assertStringContainsString('for="game-catalogue-search-query-search-fixture"', $markup);
            self::assertStringContainsString('id="game-catalogue-search-query-search-fixture"', $markup);

            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            self::assertFalse($registry->available(PublicGameCatalogueSearchWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicGameCatalogueSearchWidgetProvider::KEY, []));
            $availableKeys = array_map(
                static fn (WidgetDefinition $item): string => $item->key,
                $registry->availableDefinitions(),
            );
            self::assertNotContains(PublicGameCatalogueSearchWidgetProvider::KEY, $availableKeys);
        } finally {
            $this->removeUser($client, $userId);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the page-builder widget test.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if ($state === null) {
            $state = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /**
     * @param array{exists: bool, enabled: bool} $snapshot
     */
    private function restoreGamingModuleState(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if (!$snapshot['exists']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
                $entityManager->flush();
            }

            return;
        }

        if ($state instanceof CmsModuleState && $state->isEnabled() !== $snapshot['enabled']) {
            $state->setEnabled($snapshot['enabled']);
            $entityManager->flush();
        }
    }

    private function removeUser(KernelBrowser $client, int $userId): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
