<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use App\Module\CmsModuleManager;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class GameCataloguePaginationTest extends WebTestCase
{
    public function testPublicCataloguePaginatesDistinctStableFilteredEntries(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $fixtures = [];
        $releaseIds = [];
        $genreIds = [];
        $platformId = null;

        try {
            $entityManager = $this->entityManager($client);
            $genre = new GameGenre('WCP614 Genre '.$token, 'wcp614-genre-'.$token);
            $secondaryGenre = new GameGenre('WCP614 Extra '.$token, 'wcp614-extra-'.$token);
            $platform = new GamePlatform('WCP614 Platform '.$token, 'wcp614-platform-'.$token);
            $entityManager->persist($genre);
            $entityManager->persist($secondaryGenre);
            $entityManager->persist($platform);
            $entityManager->flush();

            $genreId = $genre->getId();
            $secondaryGenreId = $secondaryGenre->getId();
            $platformId = $platform->getId();
            self::assertNotNull($genreId);
            self::assertNotNull($secondaryGenreId);
            self::assertNotNull($platformId);
            $genreIds = [$genreId, $secondaryGenreId];

            /** @var list<array{name: string, fixture: array{game: Game, entry: GameCatalogueEntry, releases: list<GameRelease>}}> $pending */
            $pending = [];
            for ($index = 1; $index <= 25; ++$index) {
                if ($index <= 18) {
                    $name = sprintf('WCP614 A %02d', $index);
                } elseif ($index <= 20) {
                    $name = 'WCP614 B Tie';
                } else {
                    $name = sprintf('WCP614 C %02d', $index - 20);
                }

                $pending[] = [
                    'name' => $name,
                    'fixture' => $this->createEntry(
                        $entityManager,
                        $token,
                        sprintf('visible-%02d', $index),
                        $name,
                        $genre,
                        $index === 1 ? $secondaryGenre : null,
                        $platform,
                        releaseCount: $index === 1 ? 2 : 1,
                    ),
                ];
            }
            $pending[] = [
                'name' => 'WCP614 Hidden Disabled Entry',
                'fixture' => $this->createEntry(
                    $entityManager,
                    $token,
                    'disabled-entry',
                    'WCP614 Hidden Disabled Entry',
                    $genre,
                    null,
                    $platform,
                    entryEnabled: false,
                ),
            ];
            $pending[] = [
                'name' => 'WCP614 Hidden Disabled Game',
                'fixture' => $this->createEntry(
                    $entityManager,
                    $token,
                    'disabled-game',
                    'WCP614 Hidden Disabled Game',
                    $genre,
                    null,
                    $platform,
                    gameEnabled: false,
                ),
            ];
            $entityManager->flush();

            // The internal Gaming search index calls publicEntries() without a page.
            // Preserve its full public catalogue enumeration when the HTTP listing paginates.
            /** @var GameCatalogueEntryRepository $repository */
            $repository = $client->getContainer()->get(GameCatalogueEntryRepository::class);
            self::assertCount(25, $repository->publicEntries(
                'wcp614-genre-'.$token,
                'wcp614-platform-'.$token,
            ));

            /** @var list<array{name: string, gameId: int, entryId: int}> $records */
            $records = [];
            foreach ($pending as $item) {
                $gameId = $item['fixture']['game']->getId();
                $entryId = $item['fixture']['entry']->getId();
                if ($gameId === null || $entryId === null) {
                    throw new \LogicException('Persisted Game Catalogue fixture has no database IDs.');
                }

                $fixtures[] = ['gameId' => $gameId, 'entryId' => $entryId];
                foreach ($item['fixture']['releases'] as $release) {
                    $releaseId = $release->getId();
                    self::assertNotNull($releaseId);
                    $releaseIds[] = $releaseId;
                }
                if (str_starts_with($item['name'], 'WCP614 A ') || $item['name'] === 'WCP614 B Tie' || str_starts_with($item['name'], 'WCP614 C ')) {
                    $records[] = ['name' => $item['name'], 'gameId' => $gameId, 'entryId' => $entryId];
                }
            }

            usort($records, static function (array $left, array $right): int {
                $nameComparison = strcmp($left['name'], $right['name']);

                return $nameComparison !== 0 ? $nameComparison : ($left['entryId'] <=> $right['entryId']);
            });
            $expectedGameIds = array_map(static fn (array $record): string => (string) $record['gameId'], $records);
            $genreSlug = 'wcp614-genre-'.$token;
            $platformSlug = 'wcp614-platform-'.$token;
            $filteredUrl = '/games?genre='.$genreSlug.'&platform='.$platformSlug;

            $client->request('GET', $filteredUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(20, '.game-catalogue-entry');
            self::assertSelectorTextContains('.game-catalogue-count', '25 Spiele mit diesen Filtern');
            self::assertSelectorTextContains('.game-catalogue-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('.game-catalogue-pagination a[rel="next"]');

            $firstPageIds = $client->getCrawler()->filter('.game-catalogue-entry')->each(
                static fn (Crawler $node): string => (string) $node->attr('data-game-id'),
            );
            self::assertSame(array_slice($expectedGameIds, 0, 20), $firstPageIds);
            $nextUrl = (string) $client->getCrawler()->filter('.game-catalogue-pagination a[rel="next"]')->attr('href');
            self::assertStringContainsString('genre='.$genreSlug, $nextUrl);
            self::assertStringContainsString('platform='.$platformSlug, $nextUrl);
            self::assertStringContainsString('page=2', $nextUrl);

            $client->request('GET', $filteredUrl.'&page=2');
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(5, '.game-catalogue-entry');
            self::assertSelectorTextContains('.game-catalogue-count', '25 Spiele mit diesen Filtern');
            self::assertSelectorTextContains('.game-catalogue-pagination', 'Seite 2 von 2');
            self::assertSelectorExists('.game-catalogue-pagination a[rel="prev"]');
            $secondPageIds = $client->getCrawler()->filter('.game-catalogue-entry')->each(
                static fn (Crawler $node): string => (string) $node->attr('data-game-id'),
            );
            self::assertSame(array_slice($expectedGameIds, 20), $secondPageIds);

            $client->request('GET', $filteredUrl.'&page=3');
            self::assertResponseStatusCodeSame(404);

            foreach (['/games?page=abc', '/games?page[]=2', '/games?page=1001'] as $invalidUrl) {
                $client->request('GET', $invalidUrl);
                self::assertResponseStatusCodeSame(400);
            }
        } finally {
            $this->removeFixtures($client, $fixtures, $releaseIds, $genreIds, $platformId);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesThePublicCatalogueRoute(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);

        try {
            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/games');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{game: Game, entry: GameCatalogueEntry, releases: list<GameRelease>}
     */
    private function createEntry(
        EntityManagerInterface $entityManager,
        string $token,
        string $suffix,
        string $name,
        GameGenre $genre,
        ?GameGenre $secondaryGenre,
        GamePlatform $platform,
        bool $entryEnabled = true,
        bool $gameEnabled = true,
        int $releaseCount = 1,
    ): array {
        $game = (new Game())
            ->setName($name)
            ->setSlug('wcp614-'.$token.'-'.$suffix)
            ->setEnabled($gameEnabled);
        $entry = (new GameCatalogueEntry($game))
            ->setSummary('WCP614 catalogue entry '.$suffix)
            ->setDeveloper('WCP614 test studio')
            ->setEnabled($entryEnabled)
            ->addGenre($genre);
        if ($secondaryGenre instanceof GameGenre) {
            $entry->addGenre($secondaryGenre);
        }

        $entityManager->persist($game);
        $entityManager->persist($entry);
        $releases = [];
        for ($index = 0; $index < $releaseCount; ++$index) {
            $release = new GameRelease(
                $entry,
                $platform,
                'Global',
                new \DateTimeImmutable('+'.$index.' month'),
            );
            if ($index === 1) {
                $release->setStatus('released');
            }
            $entityManager->persist($release);
            $releases[] = $release;
        }

        return ['game' => $game, 'entry' => $entry, 'releases' => $releases];
    }

    /**
     * @param list<array{gameId: int, entryId: int}> $fixtures
     * @param list<int> $releaseIds
     * @param list<int> $genreIds
     */
    private function removeFixtures(
        KernelBrowser $client,
        array $fixtures,
        array $releaseIds,
        array $genreIds,
        ?int $platformId,
    ): void {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($releaseIds as $id) {
            $release = $entityManager->find(GameRelease::class, $id);
            if ($release instanceof GameRelease) {
                $entityManager->remove($release);
            }
        }
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
        foreach ($genreIds as $id) {
            $genre = $entityManager->find(GameGenre::class, $id);
            if ($genre instanceof GameGenre) {
                $entityManager->remove($genre);
            }
        }
        if ($platformId !== null) {
            $platform = $entityManager->find(GamePlatform::class, $platformId);
            if ($platform instanceof GamePlatform) {
                $entityManager->remove($platform);
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
            self::markTestSkipped('The Gaming module must be installed for the public Game Catalogue pagination test.');
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
