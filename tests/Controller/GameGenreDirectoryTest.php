<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\GameGenreDirectory\PublicGameGenreDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameGenreDirectoryTest extends WebTestCase
{
    public function testDirectoryAndProfileExposeEnabledCatalogueEntriesOnly(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $entityManager = $this->em($client);
        $visibleGenre = new GameGenre('!WCP653 Genre '.$suffix, 'wcp653-genre-'.$suffix);
        $hiddenGenre = new GameGenre('!WCP653 Hidden '.$suffix, 'wcp653-hidden-'.$suffix);
        $entities = [$visibleGenre, $hiddenGenre];
        $gameIds = [];
        $entryIds = [];
        $genreIds = [];

        foreach ([
            ['!WCP653 A '.$suffix, 'a', true, true, $visibleGenre],
            ['!WCP653 B '.$suffix, 'b', true, true, $visibleGenre],
            ['!WCP653 Disabled Entry '.$suffix, 'disabled-entry', false, true, $visibleGenre],
            ['!WCP653 Disabled Game '.$suffix, 'disabled-game', true, false, $visibleGenre],
            ['!WCP653 Hidden Only '.$suffix, 'hidden-only', false, true, $hiddenGenre],
        ] as [$name, $slug, $entryEnabled, $gameEnabled, $genre]) {
            $game = (new Game())
                ->setName($name)
                ->setSlug('wcp653-'.$slug.'-'.$suffix)
                ->setEnabled($gameEnabled);
            $entry = (new GameCatalogueEntry($game))->setEnabled($entryEnabled)->addGenre($genre);
            $entities[] = $game;
            $entities[] = $entry;
        }
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        foreach ($entities as $entity) {
            if ($entity instanceof Game) {
                self::assertNotNull($entity->getId());
                $gameIds[] = $entity->getId();
            } elseif ($entity instanceof GameCatalogueEntry) {
                self::assertNotNull($entity->getId());
                $entryIds[] = $entity->getId();
            } elseif ($entity instanceof GameGenre) {
                self::assertNotNull($entity->getId());
                $genreIds[] = $entity->getId();
            }
        }

        try {
            $client->request('GET', '/game-genres');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $visibleGenre->getName());
            self::assertSelectorTextContains('body', '2 sichtbare Spiele');
            self::assertSelectorTextNotContains('body', $hiddenGenre->getName());

            $client->request('GET', '/game-genres/'.$visibleGenre->getSlug());
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('!WCP653 A '.$suffix, $html);
            self::assertStringContainsString('!WCP653 B '.$suffix, $html);
            self::assertLessThan(strpos($html, '!WCP653 B '.$suffix), strpos($html, '!WCP653 A '.$suffix));
            self::assertStringNotContainsString('Disabled Entry', $html);
            self::assertStringNotContainsString('Disabled Game', $html);

            $client->request('GET', '/game-genres/'.$hiddenGenre->getSlug());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $entryIds, $genreIds, $gameIds);
        }
    }

    public function testProfilePaginationIsBounded(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $entityManager = $this->em($client);
        $genre = new GameGenre('!WCP653 Paged '.$suffix, 'wcp653-paged-'.$suffix);
        $entityManager->persist($genre);
        $games = [];
        $entries = [];
        for ($index = 0; $index <= PublicGameGenreDirectory::PAGE_SIZE; ++$index) {
            $number = sprintf('%02d', $index);
            $game = (new Game())->setName('!WCP653 Page '.$number.' '.$suffix)->setSlug('wcp653-page-'.$number.'-'.$suffix);
            $entry = (new GameCatalogueEntry($game))->addGenre($genre);
            $entityManager->persist($game);
            $entityManager->persist($entry);
            $games[] = $game;
            $entries[] = $entry;
        }
        $entityManager->flush();
        $gameIds = array_map(static fn (Game $game): int => $game->getId() ?? throw new \LogicException(), $games);
        $entryIds = array_map(static fn (GameCatalogueEntry $entry): int => $entry->getId() ?? throw new \LogicException(), $entries);
        $genreId = $genre->getId() ?? throw new \LogicException();

        try {
            $client->request('GET', '/game-genres/'.$genre->getSlug());
            self::assertResponseIsSuccessful();
            self::assertCount(PublicGameGenreDirectory::PAGE_SIZE, $client->getCrawler()->filter('main li.panel'));
            self::assertSelectorExists('a[href="/game-genres/'.$genre->getSlug().'?page=2"]');

            $client->request('GET', '/game-genres/'.$genre->getSlug().'?page=2');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('main li.panel'));
        } finally {
            $this->cleanup($client, $entryIds, [$genreId], $gameIds);
        }
    }

    public function testInvalidInputAndDisabledGamingFailClosed(): void
    {
        $client = static::createClient();
        foreach (['0', '101', '-1', 'abc', '1.5'] as $page) {
            $client->request('GET', '/game-genres?page='.$page);
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', '/game-genres/INVALID');
        self::assertResponseStatusCodeSame(404);

        $entityManager = $this->em($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $created = !$state instanceof CmsModuleState;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/game-genres');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $restoreManager = $this->em($client);
            $current = $restoreManager->find(CmsModuleState::class, 'gaming');
            if ($created) {
                if ($current instanceof CmsModuleState) {
                    $restoreManager->remove($current);
                }
            } elseif ($current instanceof CmsModuleState) {
                $current->setEnabled($wasEnabled);
            }
            $restoreManager->flush();
        }
    }

    /** @param list<int> $entryIds @param list<int> $genreIds @param list<int> $gameIds */
    private function cleanup(KernelBrowser $client, array $entryIds, array $genreIds, array $gameIds): void
    {
        $entityManager = $this->em($client);
        foreach ($entryIds as $id) {
            $entity = $entityManager->find(GameCatalogueEntry::class, $id);
            if ($entity instanceof GameCatalogueEntry) {
                $entityManager->remove($entity);
            }
        }
        $entityManager->flush();
        foreach ($genreIds as $id) {
            $entity = $entityManager->find(GameGenre::class, $id);
            if ($entity instanceof GameGenre) {
                $entityManager->remove($entity);
            }
        }
        foreach ($gameIds as $id) {
            $entity = $entityManager->find(Game::class, $id);
            if ($entity instanceof Game) {
                $entityManager->remove($entity);
            }
        }
        $entityManager->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
