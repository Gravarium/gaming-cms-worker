<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GamePublisherDirectoryTest extends WebTestCase
{
    public function testDirectoryAndProfileExposeOnlyPublishersWithVisibleGames(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $token = bin2hex(random_bytes(6));

        $visiblePublisher = new GamePublisher('Visible Publisher '.$token, 'visible-publisher-'.$token);
        $hiddenPublisher = new GamePublisher('Hidden Publisher '.$token, 'hidden-publisher-'.$token);
        $disabledEntryPublisher = new GamePublisher('Disabled Entry Publisher '.$token, 'disabled-entry-publisher-'.$token);

        $visibleGame = (new Game())->setName('Visible Publisher Game '.$token)->setSlug('visible-publisher-game-'.$token);
        $visibleEntry = (new GameCatalogueEntry($visibleGame))->setPublisher($visiblePublisher);
        $hiddenGame = (new Game())->setName('Hidden Publisher Game '.$token)->setSlug('hidden-publisher-game-'.$token)->setEnabled(false);
        $hiddenEntry = (new GameCatalogueEntry($hiddenGame))->setPublisher($hiddenPublisher);
        $disabledEntryGame = (new Game())->setName('Disabled Entry Game '.$token)->setSlug('disabled-entry-game-'.$token);
        $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))->setPublisher($disabledEntryPublisher)->setEnabled(false);

        foreach ([$visiblePublisher, $hiddenPublisher, $disabledEntryPublisher, $visibleGame, $visibleEntry, $hiddenGame, $hiddenEntry, $disabledEntryGame, $disabledEntry] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $ids = [
            'entries' => [$visibleEntry->getId(), $hiddenEntry->getId(), $disabledEntry->getId()],
            'games' => [$visibleGame->getId(), $hiddenGame->getId(), $disabledEntryGame->getId()],
            'publishers' => [$visiblePublisher->getId(), $hiddenPublisher->getId(), $disabledEntryPublisher->getId()],
        ];

        try {
            $client->request('GET', '/games/publishers');
            self::assertResponseIsSuccessful();
            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($visiblePublisher->getName(), $body);
            self::assertStringNotContainsString($hiddenPublisher->getName(), $body);
            self::assertStringNotContainsString($disabledEntryPublisher->getName(), $body);

            $client->request('GET', '/games/publishers/'.$visiblePublisher->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $visibleGame->getName());

            $client->request('GET', '/games/publishers/'.$hiddenPublisher->getSlug());
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/games/publishers/'.$disabledEntryPublisher->getSlug());
            self::assertResponseStatusCodeSame(404);

            foreach (['0', '-1', '0001', '100000', ['invalid']] as $invalidPage) {
                $client->request('GET', '/games/publishers', ['page' => $invalidPage]);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            $this->removeFixtures($client, $ids);
        }
    }

    /** @param array{entries: list<int|null>, games: list<int|null>, publishers: list<int|null>} $ids */
    private function removeFixtures(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();

        foreach ([
            [GameCatalogueEntry::class, $ids['entries']],
            [Game::class, $ids['games']],
            [GamePublisher::class, $ids['publishers']],
        ] as [$class, $entityIds]) {
            foreach ($entityIds as $id) {
                if ($id !== null && ($entity = $entityManager->find($class, $id)) !== null) {
                    $entityManager->remove($entity);
                }
            }
            $entityManager->flush();
        }
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
