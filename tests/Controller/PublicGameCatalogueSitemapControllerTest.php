<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\GameCatalogueSitemap\PublicGameCatalogueSitemapQuery;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameCatalogueSitemapControllerTest extends WebTestCase
{
    public function testIndexAndPagesIncludeOnlyEnabledGamesWithStableBoundedUrls(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $token = bin2hex(random_bytes(6));
        /** @var list<int> $entryIds */
        $entryIds = [];
        /** @var list<int> $gameIds */
        $gameIds = [];

        try {
            $this->setGamingModuleEnabled($client, true);
            $entityManager = $this->entityManager($client);
            $sitemapQuery = $client->getContainer()->get(PublicGameCatalogueSitemapQuery::class);
            $baselinePublicGames = $sitemapQuery->countPublicGames();
            /** @var list<GameCatalogueEntry> $entries */
            $entries = [];
            /** @var list<Game> $games */
            $games = [];

            for ($index = 1; $index <= PublicGameCatalogueSitemapQuery::PAGE_SIZE + 1; ++$index) {
                $suffix = sprintf('%05d', $index);
                $name = match ($index) {
                    1 => 'WCP560 stable tie '.$token,
                    2 => 'wcp560 STABLE TIE '.$token,
                    default => 'WCP560 game '.$suffix.' '.$token,
                };
                $game = (new Game())
                    ->setName($name)
                    ->setSlug('wcp560-game-'.$token.'-'.$suffix);
                $entry = new GameCatalogueEntry($game);
                $entityManager->persist($game);
                $entityManager->persist($entry);
                $games[] = $game;
                $entries[] = $entry;
            }

            $disabledGame = (new Game())
                ->setName('WCP560 disabled game '.$token)
                ->setSlug('wcp560-disabled-game-'.$token)
                ->setEnabled(false);
            $disabledGameEntry = new GameCatalogueEntry($disabledGame);
            $disabledEntryGame = (new Game())
                ->setName('WCP560 disabled entry game '.$token)
                ->setSlug('wcp560-disabled-entry-'.$token);
            $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))->setEnabled(false);
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

            $totalPublicGames = $baselinePublicGames + PublicGameCatalogueSitemapQuery::PAGE_SIZE + 1;

            $client->request('GET', '/sitemap-games.xml');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
            $indexXml = (string) $client->getResponse()->getContent();
            $expectedPages = max(1, (int) ceil($totalPublicGames / PublicGameCatalogueSitemapQuery::PAGE_SIZE));
            self::assertSame($expectedPages, substr_count($indexXml, '<sitemap>'));
            self::assertStringContainsString('/sitemap-games/1.xml', $indexXml);
            self::assertStringContainsString('/sitemap-games/2.xml', $indexXml);
            self::assertStringContainsString('http://localhost/sitemap-games/1.xml', $indexXml);

            $client->request('GET', '/sitemap-games/1.xml');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
            $pageOne = (string) $client->getResponse()->getContent();
            self::assertSame(min(PublicGameCatalogueSitemapQuery::PAGE_SIZE, $totalPublicGames), substr_count($pageOne, '<url>'));
            self::assertStringContainsString('/games/wcp560-game-'.$token.'-00001', $pageOne);
            $firstTiePosition = strpos($pageOne, '/games/wcp560-game-'.$token.'-00001');
            $secondTiePosition = strpos($pageOne, '/games/wcp560-game-'.$token.'-00002');
            self::assertNotFalse($firstTiePosition);
            self::assertNotFalse($secondTiePosition);
            self::assertLessThan($secondTiePosition, $firstTiePosition);
            $lastFixtureSlug = 'wcp560-game-'.$token.'-'.sprintf('%05d', PublicGameCatalogueSitemapQuery::PAGE_SIZE + 1);
            self::assertStringNotContainsString('/games/'.$lastFixtureSlug, $pageOne);
            self::assertStringNotContainsString('wcp560-disabled-game-'.$token, $pageOne);
            self::assertStringNotContainsString('wcp560-disabled-entry-'.$token, $pageOne);

            $client->request('GET', '/sitemap-games/2.xml');
            self::assertResponseIsSuccessful();
            $pageTwo = (string) $client->getResponse()->getContent();
            self::assertSame(min(PublicGameCatalogueSitemapQuery::PAGE_SIZE, $totalPublicGames - PublicGameCatalogueSitemapQuery::PAGE_SIZE), substr_count($pageTwo, '<url>'));
            self::assertStringContainsString('/games/'.$lastFixtureSlug, $pageTwo);

            $client->request('GET', '/sitemap-games/0.xml');
            self::assertResponseStatusCodeSame(400);
            $client->request('GET', '/sitemap-games/3.xml');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/sitemap-games.xml');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeFixtures($client, $entryIds, $gameIds);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesSitemapIndexAndPages(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);

        try {
            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/sitemap-games.xml');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/sitemap-games/1.xml');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    /**
     * @return array{
     *     gaming: array{exists: bool, enabled: bool},
     *     content: array{exists: bool, enabled: bool}
     * }
     */
    private function captureModuleStates(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the public game sitemap test.');
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
    private function restoreModuleStates(KernelBrowser $client, array $snapshot): void
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

    /** @param list<int> $entryIds @param list<int> $gameIds */
    private function removeFixtures(KernelBrowser $client, array $entryIds, array $gameIds): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach (array_unique($entryIds) as $id) {
            $entry = $entityManager->find(GameCatalogueEntry::class, $id);
            if ($entry instanceof GameCatalogueEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        foreach (array_unique($gameIds) as $id) {
            $game = $entityManager->find(Game::class, $id);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
        }
        $entityManager->flush();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Persisted sitemap fixture has no database ID.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
