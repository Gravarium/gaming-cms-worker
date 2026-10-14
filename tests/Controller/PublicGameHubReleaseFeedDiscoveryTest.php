<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameHubReleaseFeedDiscoveryTest extends WebTestCase
{
    public function testGameHubLinksToThePublicReleaseFeedDirectory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $previous = $this->setGamingEnabled($entityManager, true);
        $slug = 'feed-game-'.bin2hex(random_bytes(4));
        $game = (new Game())->setName('Feed Game '.$slug)->setSlug($slug)->setEnabled(true);
        $entry = new GameCatalogueEntry($game);
        $entityManager->persist($game);
        $entityManager->persist($entry);
        $entityManager->flush();

        try {
            $client->request('GET', '/games/'.$slug);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/games/releases/feeds"]');
            self::assertSelectorTextContains('a[href="/games/releases/feeds"]', 'Release-Feeds abonnieren');
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
