<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\GameGenreDirectory\PublicGameGenreDirectoryQuery;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicGameGenreDirectoryControllerTest extends WebTestCase
{
    public function testGenrePagesFilterPrivateRowsAndPaginateGamesStably(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $token = bin2hex(random_bytes(6));
        $visibleSlug = 'wcp557-visible-'.$token;
        $hiddenSlug = 'wcp557-hidden-'.$token;
        $visibleGenre = new GameGenre('WCP557 & <script>Action</script> '.$token, $visibleSlug);
        $hiddenGenre = new GameGenre('Hidden WCP557 '.$token, $hiddenSlug);
        /** @var list<GameCatalogueEntry> $entries */
        $entries = [];
        /** @var list<Game> $games */
        $games = [];
        $entryIds = [];
        $gameIds = [];
        $genreIds = [];

        try {
            $this->setGamingModuleEnabled($client, true);
            $entityManager = $this->entityManager($client);
            $entityManager->persist($visibleGenre);
            $entityManager->persist($hiddenGenre);

            for ($index = 1; $index <= 21; ++$index) {
                $suffix = sprintf('%02d', $index);
                $game = (new Game())
                    ->setName('WCP557 game '.$suffix.' '.$token)
                    ->setSlug('wcp557-'.$token.'-'.$suffix);
                $entry = (new GameCatalogueEntry($game))->addGenre($visibleGenre);
                $games[] = $game;
                $entries[] = $entry;
                $entityManager->persist($game);
                $entityManager->persist($entry);
            }

            $disabledGame = (new Game())
                ->setName('WCP557 disabled game '.$token)
                ->setSlug('wcp557-disabled-game-'.$token)
                ->setEnabled(false);
            $disabledGameEntry = (new GameCatalogueEntry($disabledGame))->addGenre($visibleGenre);
            $disabledEntryGame = (new Game())
                ->setName('WCP557 disabled entry game '.$token)
                ->setSlug('wcp557-disabled-entry-'.$token);
            $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))
                ->setEnabled(false)
                ->addGenre($visibleGenre)
                ->addGenre($hiddenGenre);
            array_push($games, $disabledGame, $disabledEntryGame);
            array_push($entries, $disabledGameEntry, $disabledEntry);
            foreach ([$disabledGame, $disabledEntryGame] as $game) {
                $entityManager->persist($game);
            }
            foreach ([$disabledGameEntry, $disabledEntry] as $entry) {
                $entityManager->persist($entry);
            }
            $entityManager->flush();

            foreach ($entries as $entry) {
                $entryIds[] = $this->requireId($entry->getId());
            }
            foreach ($games as $game) {
                $gameIds[] = $this->requireId($game->getId());
            }
            $genreIds = [
                $this->requireId($visibleGenre->getId()),
                $this->requireId($hiddenGenre->getId()),
            ];

            $client->request('GET', '/games/genres');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.game-genre-link[href="/games/genres/'.$visibleSlug.'"]');
            self::assertStringNotContainsString('Hidden WCP557 '.$token, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('&lt;script&gt;Action&lt;/script&gt;', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('<script>Action</script>', (string) $client->getResponse()->getContent());

            $client->request('GET', '/games/genres/'.$visibleSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(20, '.game-genre-game');
            self::assertSelectorTextContains('h1', 'WCP557 & <script>Action</script> '.$token);
            self::assertSame(
                array_map('strval', array_slice($gameIds, 0, 20)),
                $client->getCrawler()->filter('.game-genre-game')->each(
                    static fn (Crawler $node): string => (string) $node->attr('data-game-id'),
                ),
            );
            self::assertStringNotContainsString('WCP557 disabled game '.$token, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('WCP557 disabled entry game '.$token, (string) $client->getResponse()->getContent());

            $client->request('GET', '/games/genres/'.$visibleSlug.'?page=2');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.game-genre-game');
            self::assertSame(
                [(string) $gameIds[20]],
                $client->getCrawler()->filter('.game-genre-game')->each(
                    static fn (Crawler $node): string => (string) $node->attr('data-game-id'),
                ),
            );

            $client->request('GET', '/games/genres/'.$visibleSlug.'?page=invalid');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/games/genres/'.$visibleSlug.'?page=3');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/genres/'.$hiddenSlug);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/genres/not-a-public-genre-'.$token);
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/games/genres');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeFixtures($client, $entryIds, $gameIds, $genreIds);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testGenreDirectoryUsesStableFortyGenrePages(): void
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
            for ($index = 1; $index <= 41; ++$index) {
                $suffix = sprintf('%02d', $index);
                $genre = new GameGenre('WCP557 directory '.$suffix.' '.$token, 'wcp557-directory-'.$token.'-'.$suffix);
                $game = (new Game())
                    ->setName('WCP557 directory game '.$suffix.' '.$token)
                    ->setSlug('wcp557-directory-game-'.$token.'-'.$suffix);
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

            $query = $client->getContainer()->get(PublicGameGenreDirectoryQuery::class);
            $expectedFirstPage = array_map(
                static fn (GameGenre $genre): string => '/games/genres/'.$genre->getSlug(),
                $query->findPublicGenres(1),
            );
            $expectedSecondPage = array_map(
                static fn (GameGenre $genre): string => '/games/genres/'.$genre->getSlug(),
                $query->findPublicGenres(2),
            );
            self::assertGreaterThan(0, $query->countPublicGenres() - PublicGameGenreDirectoryQuery::GENRE_PAGE_SIZE);

            $client->request('GET', '/games/genres');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(PublicGameGenreDirectoryQuery::GENRE_PAGE_SIZE, '.game-genre-link');
            self::assertSame(
                $expectedFirstPage,
                $client->getCrawler()->filter('.game-genre-link')->each(
                    static fn (Crawler $node): string => (string) $node->attr('href'),
                ),
            );
            $nextPage = $client->getCrawler()->filter('.game-genre-pagination a[rel="next"]')->attr('href');
            self::assertNotNull($nextPage);

            $client->request('GET', $nextPage);
            self::assertResponseIsSuccessful();
            self::assertSame(
                $expectedSecondPage,
                $client->getCrawler()->filter('.game-genre-link')->each(
                    static fn (Crawler $node): string => (string) $node->attr('href'),
                ),
            );
        } finally {
            $this->removeFixtures($client, $entryIds, $gameIds, $genreIds);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesBothGenreRoutes(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);

        try {
            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/games/genres');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/genres/example-genre');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /** @return array{exists: bool, enabled: bool} */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the public genre directory test.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /** @param array{exists: bool, enabled: bool} $snapshot */
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
            throw new \LogicException('Persisted game genre fixture has no database ID.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
