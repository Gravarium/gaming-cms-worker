<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GamePlatformDirectoryTest extends WebTestCase
{
    public function testDirectoryAndProfileExposeOnlyVisibleNonCancelledReleases(): void
    {
        $client = static::createClient();
        $snapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $token = bin2hex(random_bytes(6));

        $visiblePlatform = new GamePlatform('Visible Platform '.$token, 'visible-platform-'.$token);
        $cancelledPlatform = new GamePlatform('Cancelled Platform '.$token, 'cancelled-platform-'.$token);
        $hiddenPlatform = new GamePlatform('Hidden Platform '.$token, 'hidden-platform-'.$token);
        $disabledEntryPlatform = new GamePlatform('Disabled Entry Platform '.$token, 'disabled-entry-platform-'.$token);

        $visibleGame = (new Game())->setName('Visible Platform Game '.$token)->setSlug('visible-platform-game-'.$token);
        $visibleEntry = new GameCatalogueEntry($visibleGame);
        $cancelledGame = (new Game())->setName('Cancelled Platform Game '.$token)->setSlug('cancelled-platform-game-'.$token);
        $cancelledEntry = new GameCatalogueEntry($cancelledGame);
        $hiddenGame = (new Game())->setName('Hidden Platform Game '.$token)->setSlug('hidden-platform-game-'.$token)->setEnabled(false);
        $hiddenEntry = new GameCatalogueEntry($hiddenGame);
        $disabledEntryGame = (new Game())->setName('Disabled Entry Platform Game '.$token)->setSlug('disabled-entry-platform-game-'.$token);
        $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))->setEnabled(false);

        $visibleRelease = new GameRelease($visibleEntry, $visiblePlatform, 'EU', new \DateTimeImmutable('2030-05-01'));
        $cancelledRelease = (new GameRelease($cancelledEntry, $cancelledPlatform, 'EU', new \DateTimeImmutable('2030-05-02')))->setStatus('cancelled');
        $hiddenRelease = new GameRelease($hiddenEntry, $hiddenPlatform, 'EU', new \DateTimeImmutable('2030-05-03'));
        $disabledEntryRelease = new GameRelease($disabledEntry, $disabledEntryPlatform, 'EU', new \DateTimeImmutable('2030-05-04'));

        foreach ([$visiblePlatform, $cancelledPlatform, $hiddenPlatform, $disabledEntryPlatform, $visibleGame, $visibleEntry, $cancelledGame, $cancelledEntry, $hiddenGame, $hiddenEntry, $disabledEntryGame, $disabledEntry, $visibleRelease, $cancelledRelease, $hiddenRelease, $disabledEntryRelease] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $ids = [
            'releases' => [$visibleRelease->getId(), $cancelledRelease->getId(), $hiddenRelease->getId(), $disabledEntryRelease->getId()],
            'entries' => [$visibleEntry->getId(), $cancelledEntry->getId(), $hiddenEntry->getId(), $disabledEntry->getId()],
            'games' => [$visibleGame->getId(), $cancelledGame->getId(), $hiddenGame->getId(), $disabledEntryGame->getId()],
            'platforms' => [$visiblePlatform->getId(), $cancelledPlatform->getId(), $hiddenPlatform->getId(), $disabledEntryPlatform->getId()],
        ];

        try {
            $client->request('GET', '/games/platforms');
            self::assertResponseIsSuccessful();
            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($visiblePlatform->getName(), $body);
            self::assertStringNotContainsString($cancelledPlatform->getName(), $body);
            self::assertStringNotContainsString($hiddenPlatform->getName(), $body);
            self::assertStringNotContainsString($disabledEntryPlatform->getName(), $body);

            $client->request('GET', '/games/platforms/'.$visiblePlatform->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $visibleGame->getName());
            self::assertSelectorTextContains('body', 'EU');

            foreach ([$cancelledPlatform, $hiddenPlatform, $disabledEntryPlatform] as $platform) {
                $client->request('GET', '/games/platforms/'.$platform->getSlug());
                self::assertResponseStatusCodeSame(404);
            }

            foreach (['0', '-1', '0001', '101', '1000', ['invalid']] as $invalidPage) {
                $client->request('GET', '/games/platforms', ['page' => $invalidPage]);
                self::assertResponseStatusCodeSame(404);
            }

            $client->request('GET', '/games/platforms/'.$visiblePlatform->getSlug(), ['page' => '2']);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/platforms/INVALID');
            self::assertResponseStatusCodeSame(404);

            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/games/platforms');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/platforms/'.$visiblePlatform->getSlug());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures($client, $ids);
            $this->restoreGamingModuleState($client, $snapshot);
        }
    }

    /** @return array{exists: bool, enabled: bool} */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the platform directory test.');
        }
        $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if (!$state instanceof CmsModuleState) {
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

    /** @param array{releases: list<int|null>, entries: list<int|null>, games: list<int|null>, platforms: list<int|null>} $ids */
    private function removeFixtures(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();
        foreach ([
            [GameRelease::class, $ids['releases']],
            [GameCatalogueEntry::class, $ids['entries']],
            [Game::class, $ids['games']],
            [GamePlatform::class, $ids['platforms']],
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
