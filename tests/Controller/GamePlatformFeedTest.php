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

final class GamePlatformFeedTest extends WebTestCase
{
    public function testFeedsAreBoundedEscapedAndRespectCurrentCatalogueVisibility(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for platform feed tests.');
        }
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $snapshot = ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled(true);

        $token = bin2hex(random_bytes(6));
        $platform = new GamePlatform('Platform & Feed '.$token, 'platform-feed-'.$token);
        $game = (new Game())->setName('Visible <Feed> '.$token)->setSlug('visible-feed-'.$token);
        $entry = new GameCatalogueEntry($game);
        $release = new GameRelease($entry, $platform, 'EU, North', new \DateTimeImmutable('2031-06-01 12:00:00'));
        $cancelledGame = (new Game())->setName('Cancelled Feed '.$token)->setSlug('cancelled-feed-'.$token);
        $cancelledEntry = new GameCatalogueEntry($cancelledGame);
        $cancelled = (new GameRelease($cancelledEntry, $platform, 'EU', new \DateTimeImmutable('2031-07-01')))->setStatus('cancelled');
        $hiddenGame = (new Game())->setName('Hidden Feed '.$token)->setSlug('hidden-feed-'.$token)->setEnabled(false);
        $hiddenEntry = new GameCatalogueEntry($hiddenGame);
        $hidden = new GameRelease($hiddenEntry, $platform, 'EU', new \DateTimeImmutable('2031-08-01'));
        foreach ([$platform, $game, $entry, $release, $cancelledGame, $cancelledEntry, $cancelled, $hiddenGame, $hiddenEntry, $hidden] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $ids = [
            'releases' => [$release->getId(), $cancelled->getId(), $hidden->getId()],
            'entries' => [$entry->getId(), $cancelledEntry->getId(), $hiddenEntry->getId()],
            'games' => [$game->getId(), $cancelledGame->getId(), $hiddenGame->getId()],
            'platform' => $platform->getId(),
        ];

        try {
            $client->request('GET', '/games/platforms/'.$platform->getSlug().'/feed.xml');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'application/rss+xml; charset=UTF-8');
            self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
            $rss = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Visible &lt;Feed&gt;', $rss);
            self::assertStringContainsString('Platform &amp; Feed', $rss);
            self::assertStringNotContainsString($cancelledGame->getName(), $rss);
            self::assertStringNotContainsString($hiddenGame->getName(), $rss);
            self::assertStringNotContainsString('<Feed>', $rss);

            $client->request('GET', '/games/platforms/'.$platform->getSlug().'/calendar.ics');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'text/calendar; charset=UTF-8');
            self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
            $calendar = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('BEGIN:VCALENDAR', $calendar);
            self::assertStringContainsString('Visible <Feed>', $calendar);
            self::assertStringContainsString('EU\\, North', $calendar);
            self::assertStringNotContainsString($cancelledGame->getName(), $calendar);
            self::assertStringNotContainsString($hiddenGame->getName(), $calendar);

            $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $state);
            $state->setEnabled(false);
            $this->entityManager($client)->flush();
            $client->request('GET', '/games/platforms/'.$platform->getSlug().'/feed.xml');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/platforms/'.$platform->getSlug().'/calendar.ics');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $ids, $snapshot);
        }
    }

    /** @param array{releases:list<int|null>, entries:list<int|null>, games:list<int|null>, platform:int|null} $ids
     * @param array{exists:bool, enabled:bool} $snapshot
     */
    private function cleanup(KernelBrowser $client, array $ids, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if (!$snapshot['exists']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
            }
        } elseif ($state instanceof CmsModuleState) {
            $state->setEnabled($snapshot['enabled']);
        }
        foreach ([[GameRelease::class, $ids['releases']], [GameCatalogueEntry::class, $ids['entries']], [Game::class, $ids['games']]] as [$class, $entityIds]) {
            foreach ($entityIds as $id) {
                if ($id !== null && ($entity = $entityManager->find($class, $id)) !== null) {
                    $entityManager->remove($entity);
                }
            }
            $entityManager->flush();
        }
        if ($ids['platform'] !== null && ($platform = $entityManager->find(GamePlatform::class, $ids['platform'])) !== null) {
            $entityManager->remove($platform);
        }
        $entityManager->flush();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
