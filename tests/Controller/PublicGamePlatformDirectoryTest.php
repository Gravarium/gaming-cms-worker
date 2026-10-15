<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGamePlatformDirectoryTest extends WebTestCase
{
    public function testDirectoryAndPlatformPageOnlyExposeAvailablePublicGames(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $previousGaming = $this->prepareModule($entityManager, 'gaming');
        $previousContent = $this->prepareModule($entityManager, 'content');
        $suffix = bin2hex(random_bytes(4));

        $platform = new GamePlatform('WCP709 PC '.$suffix, 'wcp709-pc-'.$suffix);
        $game = (new Game())->setName('WCP709 public game '.$suffix)->setSlug('wcp709-public-game-'.$suffix)->setEnabled(true);
        $entry = new GameCatalogueEntry($game);
        $release = new GameRelease($entry, $platform, 'EU', new \DateTimeImmutable('+10 days'));

        $cancelledPlatform = new GamePlatform('WCP709 cancelled platform '.$suffix, 'wcp709-cancelled-'.$suffix);
        $cancelledGame = (new Game())->setName('WCP709 cancelled game '.$suffix)->setSlug('wcp709-cancelled-game-'.$suffix)->setEnabled(true);
        $cancelledEntry = new GameCatalogueEntry($cancelledGame);
        $cancelledRelease = (new GameRelease($cancelledEntry, $cancelledPlatform, 'EU', new \DateTimeImmutable('+10 days')))->setStatus('cancelled');

        $fixtures = [$platform, $game, $entry, $release, $cancelledPlatform, $cancelledGame, $cancelledEntry, $cancelledRelease];
        foreach ($fixtures as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        try {
            $client->request('GET', '/games/platforms');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/games/platforms/wcp709-pc-'.$suffix.'"]');
            self::assertSelectorTextContains('main', 'WCP709 PC '.$suffix);
            self::assertSelectorTextNotContains('main', 'WCP709 cancelled platform '.$suffix);

            $client->request('GET', '/games/platforms/wcp709-pc-'.$suffix);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'WCP709 public game '.$suffix);
            self::assertSelectorTextNotContains('main', 'WCP709 cancelled game '.$suffix);

            $client->request('GET', '/games/platforms/wcp709-cancelled-'.$suffix);
            self::assertResponseStatusCodeSame(404);

            $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
            self::assertInstanceOf(CmsModuleState::class, $state);
            $state->setEnabled(false);
            $entityManager->flush();
            $client->request('GET', '/games/platforms');
            self::assertResponseStatusCodeSame(404);
        } finally {
            try {
                $connection = $entityManager->getConnection();
                $gameSlugs = ['wcp709-public-game-'.$suffix, 'wcp709-cancelled-game-'.$suffix];
                $platformSlugs = ['wcp709-pc-'.$suffix, 'wcp709-cancelled-'.$suffix];
                $connection->executeStatement(
                    'DELETE FROM game_catalogue_release WHERE entry_id IN (SELECT entry.id FROM game_catalogue_entry entry INNER JOIN game ON game.id = entry.game_id WHERE game.slug IN (?, ?))',
                    $gameSlugs,
                );
                $connection->executeStatement(
                    'DELETE FROM game_catalogue_entry WHERE game_id IN (SELECT id FROM game WHERE slug IN (?, ?))',
                    $gameSlugs,
                );
                $connection->executeStatement('DELETE FROM game_catalogue_platform WHERE slug IN (?, ?)', $platformSlugs);
                $connection->executeStatement('DELETE FROM game WHERE slug IN (?, ?)', $gameSlugs);
            } finally {
                $this->restoreModuleState($entityManager, 'gaming', $previousGaming);
                $this->restoreModuleState($entityManager, 'content', $previousContent);
            }
        }
    }

    /** @return array{exists: bool, installed: bool, enabled: bool, version: ?string} */
    private function prepareModule(EntityManagerInterface $entityManager, string $moduleKey): array
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find($moduleKey);
        $previous = [
            'exists' => $state instanceof CmsModuleState,
            'installed' => $state?->isInstalled() ?? false,
            'enabled' => $state?->isEnabled() ?? true,
            'version' => $state?->getInstalledVersion(),
        ];

        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($moduleKey);
        }
        if (!$state->isInstalled()) {
            $state->install($state->getInstalledVersion() ?? '1.0.0');
        }
        $state->setEnabled(true);
        $entityManager->persist($state);
        $entityManager->flush();

        return $previous;
    }

    /** @param array{exists: bool, installed: bool, enabled: bool, version: ?string} $previous */
    private function restoreModuleState(EntityManagerInterface $entityManager, string $moduleKey, array $previous): void
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find($moduleKey);
        if (!$previous['exists']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
                $entityManager->flush();
            }

            return;
        }
        if (!$state instanceof CmsModuleState) {
            return;
        }

        if ($previous['installed']) {
            if (!$state->isInstalled()) {
                $state->install($previous['version'] ?? '1.0.0');
            }
            if ($previous['version'] !== null && $state->getInstalledVersion() !== $previous['version']) {
                $state->updateVersion($previous['version']);
            }
            $state->setEnabled($previous['enabled']);
        } else {
            $state->removePackage();
            if ($previous['version'] !== null && $state->getInstalledVersion() !== $previous['version']) {
                $state->updateVersion($previous['version']);
            }
        }
        $entityManager->persist($state);
        $entityManager->flush();
    }
}
