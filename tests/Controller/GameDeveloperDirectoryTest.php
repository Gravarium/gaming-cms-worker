<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameDeveloperDirectoryTest extends WebTestCase
{
    public function testDirectoryAndProfileExposeOnlyDevelopersWithVisibleGames(): void
    {
        $client = static::createClient();
        $snapshot = $this->captureGamingModuleState($client);
        $this->setGamingModuleEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $token = bin2hex(random_bytes(6));
        $visibleDeveloper = 'Visible Studio '.$token;
        $hiddenDeveloper = 'Hidden Studio '.$token;
        $disabledEntryDeveloper = 'Disabled Studio '.$token;

        $visibleGame = (new Game())->setName('Visible Developer Game '.$token)->setSlug('visible-developer-game-'.$token);
        $visibleEntry = (new GameCatalogueEntry($visibleGame))->setDeveloper($visibleDeveloper);
        $secondVisibleGame = (new Game())->setName('Second Developer Game '.$token)->setSlug('second-developer-game-'.$token);
        $secondVisibleEntry = (new GameCatalogueEntry($secondVisibleGame))->setDeveloper(mb_strtoupper($visibleDeveloper, 'UTF-8'));
        $hiddenGame = (new Game())->setName('Hidden Developer Game '.$token)->setSlug('hidden-developer-game-'.$token)->setEnabled(false);
        $hiddenEntry = (new GameCatalogueEntry($hiddenGame))->setDeveloper($hiddenDeveloper);
        $disabledEntryGame = (new Game())->setName('Disabled Entry Developer Game '.$token)->setSlug('disabled-entry-developer-game-'.$token);
        $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))->setDeveloper($disabledEntryDeveloper)->setEnabled(false);

        foreach ([$visibleGame, $visibleEntry, $secondVisibleGame, $secondVisibleEntry, $hiddenGame, $hiddenEntry, $disabledEntryGame, $disabledEntry] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $ids = [
            'entries' => [$visibleEntry->getId(), $secondVisibleEntry->getId(), $hiddenEntry->getId(), $disabledEntry->getId()],
            'games' => [$visibleGame->getId(), $secondVisibleGame->getId(), $hiddenGame->getId(), $disabledEntryGame->getId()],
        ];

        try {
            $client->request('GET', '/games/developers');
            self::assertResponseIsSuccessful();
            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString(mb_strtolower($visibleDeveloper, 'UTF-8'), mb_strtolower($body, 'UTF-8'));
            self::assertStringNotContainsString($hiddenDeveloper, $body);
            self::assertStringNotContainsString($disabledEntryDeveloper, $body);

            $client->request('GET', '/games/developers/'.rawurlencode(mb_strtoupper($visibleDeveloper, 'UTF-8')));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $visibleGame->getName());
            self::assertSelectorTextContains('body', $secondVisibleGame->getName());

            $client->request('GET', '/games/developers/'.rawurlencode($hiddenDeveloper));
            self::assertResponseStatusCodeSame(404);

            foreach (['0', '-1', '0001', '101', ['invalid']] as $invalidPage) {
                $client->request('GET', '/games/developers', ['page' => $invalidPage]);
                self::assertResponseStatusCodeSame(404);
            }

            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/games/developers');
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
            self::markTestSkipped('The Gaming module must be installed for the developer directory test.');
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

    /** @param array{entries: list<int|null>, games: list<int|null>} $ids */
    private function removeFixtures(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();
        foreach ([[GameCatalogueEntry::class, $ids['entries']], [Game::class, $ids['games']]] as [$class, $entityIds]) {
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
