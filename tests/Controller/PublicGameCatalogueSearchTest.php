<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePublisher;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicGameCatalogueSearchTest extends WebTestCase
{
    public function testSearchCoversCatalogueFieldsAndExcludesDisabledEntriesAndGames(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $fixtures = [];

        try {
            $searchCases = [
                ['name', 'WCP531 title-<script>'.$token.'</script>', null, null, null, null],
                ['description', 'Game description', 'WCP531 game-description-'.$token, null, null, null],
                ['summary', 'Summary game', null, 'WCP531 summary-'.$token, null, null],
                ['developer', 'Developer game', null, null, 'WCP531 developer-'.$token, null],
                ['publisher', 'Publisher game', null, null, null, 'WCP531 publisher-'.$token],
                ['genre', 'Genre game', null, null, null, null],
            ];

            foreach ($searchCases as [$suffix, $name, $description, $summary, $developer, $publisherName]) {
                $fixtures[] = $this->createEntry(
                    $client,
                    $token,
                    $suffix,
                    $name,
                    $description,
                    $summary,
                    $developer,
                    $publisherName,
                    $suffix === 'genre' ? 'WCP531 genre-'.$token : null,
                );
            }

            $visibleEntry = $this->createEntry(
                $client,
                $token,
                'visible',
                'Visible WCP531 '.$token,
                entryEnabled: true,
                gameEnabled: true,
            );
            $disabledEntry = $this->createEntry(
                $client,
                $token,
                'disabled-entry',
                'Hidden entry marker-'.$token,
                entryEnabled: false,
            );
            $disabledGame = $this->createEntry(
                $client,
                $token,
                'disabled-game',
                'Hidden game marker-'.$token,
                gameEnabled: false,
            );
            array_push($fixtures, $visibleEntry, $disabledEntry, $disabledGame);

            foreach ($searchCases as [$suffix]) {
                $needle = 'wcp531 '.$suffix.'-'.$token;
                if ($suffix === 'name') {
                    $needle = 'title-'.$token;
                } elseif ($suffix === 'description') {
                    $needle = 'game-description-'.$token;
                } elseif ($suffix === 'summary') {
                    $needle = 'summary-'.$token;
                } elseif ($suffix === 'developer') {
                    $needle = 'developer-'.$token;
                } elseif ($suffix === 'publisher') {
                    $needle = 'publisher-'.$token;
                } else {
                    $needle = 'genre-'.$token;
                }

                $client->request('GET', '/game-search?q='.rawurlencode(mb_strtoupper($needle, 'UTF-8')));

                self::assertResponseIsSuccessful();
                self::assertSelectorCount(1, '.game-search-result');
            }

            $client->request('GET', '/game-search?q='.rawurlencode('marker-'.$token));
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.game-search-empty');
            self::assertSelectorCount(0, '.game-search-result');

            $client->request('GET', '/game-search?q='.rawurlencode('visible WCP531 '.$token));
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.game-search-result');
            self::assertSelectorTextContains('.game-search-result h2', 'Visible WCP531 '.$token);
            self::assertSelectorExists('.game-search-result a[href^="/games/"]');

            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('<script>'.$token.'</script>', $content);
            self::assertStringContainsString('&lt;script&gt;'.$token.'&lt;/script&gt;', $content);
        } finally {
            $this->removeFixtures($client, $fixtures);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testSearchUsesStableTwentyResultPages(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $fixtures = [];
        $gameIds = [];

        try {
            for ($index = 1; $index <= 21; ++$index) {
                $fixture = $this->createEntry(
                    $client,
                    $token,
                    sprintf('page-%02d', $index),
                    sprintf('WCP531 pagination-%s-%02d', $token, $index),
                );
                $fixtures[] = $fixture;
                $gameIds[] = $fixture['gameId'];
            }

            $client->request('GET', '/game-search?q='.rawurlencode('pagination-'.$token));

            self::assertResponseIsSuccessful();
            self::assertSelectorCount(20, '.game-search-result');
            self::assertSelectorTextContains('.game-search-count', '21 Treffer');
            self::assertSelectorTextContains('.game-search-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('.game-search-pagination a[rel="next"]');
            self::assertSame(
                array_map('strval', array_slice($gameIds, 0, 20)),
                $client->getCrawler()->filter('.game-search-result')->each(
                    static fn (Crawler $node): string => (string) $node->attr('data-game-id'),
                ),
            );

            $client->request('GET', '/game-search?q='.rawurlencode('pagination-'.$token).'&page=2');

            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.game-search-result');
            self::assertSelectorTextContains('.game-search-pagination', 'Seite 2 von 2');
            self::assertSame(
                [(string) $gameIds[20]],
                $client->getCrawler()->filter('.game-search-result')->each(
                    static fn (Crawler $node): string => (string) $node->attr('data-game-id'),
                ),
            );
        } finally {
            $this->removeFixtures($client, $fixtures);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testEmptySearchEscapesUserInputAndRejectsMalformedParameters(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $fixture = $this->createEntry($client, $token, 'one', 'Out-of-range WCP531 '.$token);

        try {
            $marker = '<script>WCP531-'.$token.'</script>';
            $client->request('GET', '/game-search?q='.rawurlencode($marker));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.game-search-empty', 'Keine passenden öffentlichen Spiele');
            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($marker, $content);
            self::assertStringContainsString(htmlspecialchars($marker, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);

            $client->request('GET', '/game-search?q='.rawurlencode('out-of-range-'.$token).'&page=2');
            self::assertResponseStatusCodeSame(404);

            $invalidUrls = [
                '/game-search?q%5B%5D=game',
                '/game-search?q=G',
                '/game-search?q='.rawurlencode(str_repeat('x', 101)),
                '/game-search?q=game%0Asecret',
                '/game-search?q=%FF',
                '/game-search?q=game&page=abc',
                '/game-search?q=game&page=1001',
                '/game-search?q=game&page%5B%5D=2',
                '/game-search?page=2',
            ];
            foreach ($invalidUrls as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(400, 'Expected malformed search input to be rejected: '.$url);
            }
        } finally {
            $this->removeFixtures($client, [$fixture]);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesThePublicSearchRoute(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);

        try {
            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/game-search?q=game');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{gameId: int, entryId: int, publisherId: ?int, genreId: ?int}
     */
    private function createEntry(
        KernelBrowser $client,
        string $token,
        string $suffix,
        string $name,
        ?string $gameDescription = null,
        ?string $summary = null,
        ?string $developer = null,
        ?string $publisherName = null,
        ?string $genreName = null,
        bool $entryEnabled = true,
        bool $gameEnabled = true,
    ): array {
        $entityManager = $this->entityManager($client);
        $game = (new Game())
            ->setName($name)
            ->setSlug('wcp531-'.$token.'-'.$suffix)
            ->setDescription($gameDescription)
            ->setEnabled($gameEnabled);
        $entry = (new GameCatalogueEntry($game))
            ->setSummary($summary)
            ->setDeveloper($developer)
            ->setEnabled($entryEnabled);

        $publisherId = null;
        if ($publisherName !== null) {
            $publisher = new GamePublisher($publisherName, 'wcp531-publisher-'.$token.'-'.$suffix);
            $entityManager->persist($publisher);
            $entry->setPublisher($publisher);
        }

        $genreId = null;
        $genre = null;
        if ($genreName !== null) {
            $genre = new GameGenre($genreName, 'wcp531-genre-'.$token.'-'.$suffix);
            $entityManager->persist($genre);
            $entry->addGenre($genre);
        }

        $entityManager->persist($game);
        $entityManager->persist($entry);
        $entityManager->flush();

        $gameId = $game->getId();
        $entryId = $entry->getId();
        if ($gameId === null || $entryId === null) {
            throw new \LogicException('Persisted Game Catalogue fixture has no database IDs.');
        }
        if ($publisherName !== null) {
            $publisherId = $entry->getPublisher()?->getId();
        }
        if ($genre instanceof GameGenre) {
            $genreId = $genre->getId();
        }

        return [
            'gameId' => $gameId,
            'entryId' => $entryId,
            'publisherId' => $publisherId,
            'genreId' => $genreId,
        ];
    }

    /**
     * @param list<array{gameId: int, entryId: int, publisherId: ?int, genreId: ?int}> $fixtures
     */
    private function removeFixtures(KernelBrowser $client, array $fixtures): void
    {
        if ($fixtures === []) {
            return;
        }

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
            if ($fixture['publisherId'] !== null) {
                $publisher = $entityManager->find(GamePublisher::class, $fixture['publisherId']);
                if ($publisher instanceof GamePublisher) {
                    $entityManager->remove($publisher);
                }
            }
            if ($fixture['genreId'] !== null) {
                $genre = $entityManager->find(GameGenre::class, $fixture['genreId']);
                if ($genre instanceof GameGenre) {
                    $entityManager->remove($genre);
                }
            }
        }
        $entityManager->flush();
    }

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the public Game Catalogue search test.');
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

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
