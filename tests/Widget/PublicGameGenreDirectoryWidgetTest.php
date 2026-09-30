<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\GameGenreDirectory\PublicGameGenreDirectoryQuery;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Widget\PublicGameGenreDirectoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicGameGenreDirectoryWidgetTest extends WebTestCase
{
    public function testPageBuilderWidgetShowsBoundedEscapedGenreLinksAndRespectsGamingModule(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $token = bin2hex(random_bytes(6));
        /** @var list<GameCatalogueEntry> $entries */
        $entries = [];
        /** @var list<Game> $games */
        $games = [];
        /** @var list<GameGenre> $genres */
        $genres = [];
        $entryIds = [];
        $gameIds = [];
        $genreIds = [];

        try {
            $this->setGamingModuleEnabled($client, true);
            $entityManager = $this->entityManager($client);
            for ($index = 1; $index <= 13; ++$index) {
                $suffix = sprintf('%02d', $index);
                $genre = new GameGenre(
                    'WCP557 widget genre '.$suffix.' '.$token,
                    'wcp557-widget-'.$token.'-'.$suffix,
                );
                $game = (new Game())
                    ->setName('WCP557 widget game '.$suffix.' '.$token)
                    ->setSlug('wcp557-widget-game-'.$token.'-'.$suffix);
                $entry = (new GameCatalogueEntry($game))->addGenre($genre);

                $genres[] = $genre;
                $games[] = $game;
                $entries[] = $entry;
                $entityManager->persist($genre);
                $entityManager->persist($game);
                $entityManager->persist($entry);
            }
            $entityManager->flush();

            foreach ($genres as $genre) {
                $genreIds[] = $this->requireId($genre->getId());
            }
            foreach ($games as $game) {
                $gameIds[] = $this->requireId($game->getId());
            }
            foreach ($entries as $entry) {
                $entryIds[] = $this->requireId($entry->getId());
            }

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(PublicGameGenreDirectoryWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertSame('widget/game_genres.html.twig', $definition->template);
            self::assertTrue($registry->available(PublicGameGenreDirectoryWidgetProvider::KEY));
            self::assertContains(
                PublicGameGenreDirectoryWidgetProvider::KEY,
                array_map(
                    static fn (WidgetDefinition $widget): string => $widget->key,
                    $registry->availableDefinitions(),
                ),
            );

            $data = $registry->data(PublicGameGenreDirectoryWidgetProvider::KEY, []);
            self::assertIsArray($data['genres'] ?? null);
            self::assertCount(PublicGameGenreDirectoryQuery::MAX_WIDGET_ITEMS, $data['genres']);

            $validator = $container->get(LayoutValidator::class);
            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'];
            $document['widgets'][] = [
                'id' => 'game-genres',
                'type' => PublicGameGenreDirectoryWidgetProvider::KEY,
                'region' => $region,
                'enabled' => true,
                'config' => [],
            ];
            $validated = $validator->validate($document);
            $view = $container->get(LayoutRenderer::class)->view($validated);
            self::assertSame(
                PublicGameGenreDirectoryWidgetProvider::KEY,
                $view['regions'][$region][1]['definition']->key,
            );

            $scriptGenre = new GameGenre('<script>WCP557 widget</script>', 'wcp557-widget-script-'.$token);
            $twig = $container->get(Environment::class);
            $markup = $twig->render($definition->template, [
                'widget' => ['id' => 'game-genres'],
                'config' => [],
                'data' => ['genres' => [$scriptGenre]],
            ]);
            self::assertStringContainsString('href="/games/genres/wcp557-widget-script-'.$token.'"', $markup);
            self::assertStringContainsString('&lt;script&gt;WCP557 widget&lt;/script&gt;', $markup);
            self::assertStringNotContainsString('<script>WCP557 widget</script>', $markup);
            self::assertStringContainsString('Alle Spielgenres', $markup);

            $emptyMarkup = $twig->render($definition->template, [
                'widget' => ['id' => 'empty-game-genres'],
                'config' => [],
                'data' => ['genres' => []],
            ]);
            self::assertStringContainsString('role="status"', $emptyMarkup);
            self::assertStringContainsString('Derzeit sind keine öffentlichen Spielgenres verfügbar.', $emptyMarkup);

            $this->setGamingModuleEnabled($client, false);
            self::assertFalse($registry->available(PublicGameGenreDirectoryWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicGameGenreDirectoryWidgetProvider::KEY, []));
            $hiddenView = $container->get(LayoutRenderer::class)->view($validated);
            self::assertCount(1, $hiddenView['regions'][$region]);
        } finally {
            $this->removeFixtures($client, $entryIds, $gameIds, $genreIds);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{
     *     gaming: array{exists: bool, enabled: bool},
     *     content: array{exists: bool, enabled: bool}
     * }
     */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the public genre surface test.');
        }

        return [
            'gaming' => $this->captureModuleState($client, 'gaming'),
            'content' => $this->captureModuleState($client, 'content'),
        ];
    }

    /** @return array{exists: bool, enabled: bool} */
    private function captureModuleState(KernelBrowser $client, string $module): array
    {
        $state = $this->entityManager($client)->find(CmsModuleState::class, $module);

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $this->setModuleEnabled($client, 'content', true);
        $this->setModuleEnabled($client, 'gaming', $enabled);
    }

    private function setModuleEnabled(KernelBrowser $client, string $module, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, $module);
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey($module)->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /**
     * @param array{
     *     gaming: array{exists: bool, enabled: bool},
     *     content: array{exists: bool, enabled: bool}
     * } $snapshot
     */
    private function restoreGamingModuleState(KernelBrowser $client, array $snapshot): void
    {
        if (!$this->entityManager($client)->isOpen()) {
            return;
        }

        $this->restoreModuleState($client, 'gaming', $snapshot['gaming']);
        $this->restoreModuleState($client, 'content', $snapshot['content']);
    }

    /** @param array{exists: bool, enabled: bool} $snapshot */
    private function restoreModuleState(KernelBrowser $client, string $module, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, $module);
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

    /**
     * @param list<int> $entryIds
     * @param list<int> $gameIds
     * @param list<int> $genreIds
     */
    private function removeFixtures(KernelBrowser $client, array $entryIds, array $gameIds, array $genreIds): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($entryIds as $id) {
            $entry = $entityManager->find(GameCatalogueEntry::class, $id);
            if ($entry instanceof GameCatalogueEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        foreach ($gameIds as $id) {
            $game = $entityManager->find(Game::class, $id);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
        }
        foreach ($genreIds as $id) {
            $genre = $entityManager->find(GameGenre::class, $id);
            if ($genre instanceof GameGenre) {
                $entityManager->remove($genre);
            }
        }
        $entityManager->flush();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Persisted genre widget fixture has no database ID.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
