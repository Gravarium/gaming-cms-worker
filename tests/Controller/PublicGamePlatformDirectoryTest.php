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
        $previous = $this->setGamingEnabled($entityManager, true);
        $suffix = bin2hex(random_bytes(4));

        $platform = new GamePlatform('WCP709 PC '.$suffix, 'wcp709-pc-'.$suffix);
        $game = (new Game())->setName('WCP709 public game '.$suffix)->setSlug('wcp709-public-game-'.$suffix)->setEnabled(true);
        $entry = new GameCatalogueEntry($game);
        $release = new GameRelease($entry, $platform, 'EU', new \DateTimeImmutable('+10 days'));

        $cancelledPlatform = new GamePlatform('WCP709 cancelled platform '.$suffix, 'wcp709-cancelled-'.$suffix);
        $cancelledGame = (new Game())->setName('WCP709 cancelled game '.$suffix)->setSlug('wcp709-cancelled-game-'.$suffix)->setEnabled(true);
        $cancelledEntry = new GameCatalogueEntry($cancelledGame);
        $cancelledRelease = (new GameRelease($cancelledEntry, $cancelledPlatform, 'EU', new \DateTimeImmutable('+10 days')))->setStatus('cancelled');

        foreach ([$platform, $game, $entry, $release, $cancelledPlatform, $cancelledGame, $cancelledEntry, $cancelledRelease] as $entity) {
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
            $this->restoreGamingState($entityManager, $previous);
        }
    }

    private function setGamingEnabled(EntityManagerInterface $entityManager, bool $enabled): ?bool
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $entityManager->persist($state);
        $entityManager->flush();

        return $previous;
    }

    private function restoreGamingState(EntityManagerInterface $entityManager, ?bool $previous): void
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        if (!$state instanceof CmsModuleState) {
            return;
        }
        if ($previous === null) {
            $entityManager->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $entityManager->flush();
    }
}
