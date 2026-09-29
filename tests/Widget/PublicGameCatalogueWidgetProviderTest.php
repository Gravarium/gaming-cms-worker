<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\GameCatalogue\PublicGameCatalogueQuery;
use App\Widget\PublicGameCatalogueWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicGameCatalogueWidgetProviderTest extends WebTestCase
{
    public function testWidgetAppearsInThePageBuilderAndRendersEscapedInternalLinks(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $fixture = $this->createEntry(
            $client,
            '!WCP524-<script>title-'.$token.'</script>',
            'wcp524-'.$token,
            '<img src=x onerror=alert(1)> summary-'.$token,
        );
        $user = $this->createEditor($client, $token);
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
            $definition = $registry->get(PublicGameCatalogueWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertTrue($registry->available(PublicGameCatalogueWidgetProvider::KEY));

            $availableKeys = array_map(
                static fn (WidgetDefinition $item): string => $item->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(PublicGameCatalogueWidgetProvider::KEY, $availableKeys);
            self::assertSame('gaming', $definition->module);
            $schema = $container->get(LayoutValidator::class)->widgetSchema(PublicGameCatalogueWidgetProvider::KEY);
            self::assertSame(6, $schema['count']['default']);
            self::assertSame(1, $schema['count']['min']);
            self::assertSame(PublicGameCatalogueQuery::MAX_ITEMS, $schema['count']['max']);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'catalogue-fixture'],
                'config' => ['count' => 6],
                'data' => $registry->data(PublicGameCatalogueWidgetProvider::KEY, ['count' => 6]),
            ]);

            self::assertStringContainsString('href="/games/wcp524-'.$token.'"', $markup);
            self::assertStringContainsString('&lt;script&gt;title-'.$token.'&lt;/script&gt;', $markup);
            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; summary-'.$token, $markup);
            self::assertStringNotContainsString('<script>title-'.$token.'</script>', $markup);
            self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $markup);
            self::assertSame($fixture['gameId'], $this->findEntry($client, $fixture['entryId'])->getGame()->getId());
        } finally {
            $this->removeEntries($client, [$fixture]);
            $this->removeUser($client, $userId);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testQueryFiltersDisabledRowsUsesStableOrderingAndCapsTheResult(): void
    {
        $client = static::createClient();
        $query = $client->getContainer()->get(PublicGameCatalogueQuery::class);
        $token = bin2hex(random_bytes(8));
        $fixtures = [];
        $expectedGameIds = [];

        try {
            for ($index = 0; $index < 10; ++$index) {
                $suffix = sprintf('%02d', $index);
                $fixture = $this->createEntry(
                    $client,
                    '!WCP524-'.$token.'-A-'.$suffix,
                    'wcp524-'.$token.'-a-'.$suffix,
                );
                $fixtures[] = $fixture;
                $expectedGameIds[] = $fixture['gameId'];
            }

            $sameNameGameIds = [];
            for ($index = 0; $index < 5; ++$index) {
                $fixture = $this->createEntry(
                    $client,
                    '!WCP524-'.$token.'-Z',
                    'wcp524-'.$token.'-z-'.$index,
                );
                $fixtures[] = $fixture;
                $sameNameGameIds[] = $fixture['gameId'];
            }

            $fixtures[] = $this->createEntry(
                $client,
                '!WCP524-'.$token.'-A-hidden-entry',
                'wcp524-'.$token.'-hidden-entry',
                null,
                entryEnabled: false,
            );
            $fixtures[] = $this->createEntry(
                $client,
                '!WCP524-'.$token.'-A-hidden-game',
                'wcp524-'.$token.'-hidden-game',
                null,
                gameEnabled: false,
            );

            $results = $query->findPublic(99);
            self::assertCount(PublicGameCatalogueQuery::MAX_ITEMS, $results);
            self::assertSame(
                [...$expectedGameIds, ...array_slice($sameNameGameIds, 0, 2)],
                array_map(static fn (GameCatalogueEntry $entry): ?int => $entry->getGame()->getId(), $results),
            );

            $shortPage = $query->findPublic(3);
            self::assertSame(
                array_slice($expectedGameIds, 0, 3),
                array_map(static fn (GameCatalogueEntry $entry): ?int => $entry->getGame()->getId(), $shortPage),
            );

            $minimumPage = $query->findPublic(0);
            self::assertCount(1, $minimumPage);
            self::assertSame($expectedGameIds[0], $minimumPage[0]->getGame()->getId());
        } finally {
            $this->removeEntries($client, $fixtures);
        }
    }

    public function testDisabledGamingHidesTheWidgetAndEmptyDataShowsAnEmptyState(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $fixture = $this->createEntry($client, '!WCP524-'.$token, 'wcp524-'.$token);
        $user = $this->createEditor($client, $token);
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
            $definition = $registry->get(PublicGameCatalogueWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertTrue($registry->available(PublicGameCatalogueWidgetProvider::KEY));

            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            self::assertFalse($registry->available(PublicGameCatalogueWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicGameCatalogueWidgetProvider::KEY, ['count' => 6]));

            $emptyMarkup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'empty-catalogue'],
                'config' => [],
                'data' => ['items' => []],
            ]);
            self::assertStringContainsString('Der Spielekatalog enthält derzeit keine öffentlichen Einträge.', $emptyMarkup);
            self::assertStringContainsString('role="status"', $emptyMarkup);
        } finally {
            $this->removeEntries($client, [$fixture]);
            $this->removeUser($client, $userId);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{gameId: int, entryId: int}
     */
    private function createEntry(
        KernelBrowser $client,
        string $name,
        string $slug,
        ?string $summary = null,
        bool $entryEnabled = true,
        bool $gameEnabled = true,
    ): array {
        $game = (new Game())
            ->setName($name)
            ->setSlug($slug)
            ->setEnabled($gameEnabled);
        $entry = (new GameCatalogueEntry($game))
            ->setSummary($summary)
            ->setEnabled($entryEnabled);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($entry);
        $entityManager->flush();

        $gameId = $game->getId();
        $entryId = $entry->getId();
        if ($gameId === null || $entryId === null) {
            throw new \LogicException('Persisted Game Catalogue fixture is missing its database ID.');
        }

        return ['gameId' => $gameId, 'entryId' => $entryId];
    }

    /**
     * @param list<array{gameId: int, entryId: int}> $fixtures
     */
    private function removeEntries(KernelBrowser $client, array $fixtures): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($fixtures as $fixture) {
            $entry = $entityManager->find(GameCatalogueEntry::class, $fixture['entryId']);
            if ($entry instanceof GameCatalogueEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        foreach ($fixtures as $fixture) {
            $game = $entityManager->find(Game::class, $fixture['gameId']);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
        }
        $entityManager->flush();
    }

    private function createEditor(KernelBrowser $client, string $token): User
    {
        $user = (new User())
            ->setEmail('public-game-widget-'.$token.'@example.test')
            ->setDisplayName('Public game widget '.$token)
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
            ->verifyEmail();

        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
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

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the Game Catalogue widget test.');
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

    private function findEntry(KernelBrowser $client, int $id): GameCatalogueEntry
    {
        $entry = $this->entityManager($client)->find(GameCatalogueEntry::class, $id);
        if (!$entry instanceof GameCatalogueEntry) {
            throw new \LogicException('Expected Game Catalogue fixture is missing.');
        }

        return $entry;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
