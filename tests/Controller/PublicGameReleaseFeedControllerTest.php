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

final class PublicGameReleaseFeedControllerTest extends WebTestCase
{
    public function testFeedFiltersOrdersEscapesAndUsesHostAwareEtags(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $token = bin2hex(random_bytes(6));
        $today = new \DateTimeImmutable('today');

        $game = (new Game())
            ->setName('WCP555 & <Games> '.$token)
            ->setSlug('wcp555-release-'.$token);
        $disabledGame = (new Game())
            ->setName('Disabled game '.$token)
            ->setSlug('wcp555-disabled-game-'.$token)
            ->setEnabled(false);
        $disabledEntryGame = (new Game())
            ->setName('Disabled entry game '.$token)
            ->setSlug('wcp555-disabled-entry-'.$token);
        $entry = new GameCatalogueEntry($game);
        $disabledGameEntry = new GameCatalogueEntry($disabledGame);
        $disabledEntry = (new GameCatalogueEntry($disabledEntryGame))->setEnabled(false);
        $platform = new GamePlatform('PC & Console', 'wcp555-platform-'.$token);

        $first = new GameRelease($entry, $platform, 'A & <North>', $today->modify('+1 day'));
        $second = (new GameRelease($entry, $platform, 'B region', $today->modify('+1 day')))->setStatus('delayed');
        $third = (new GameRelease($entry, $platform, 'C region', $today->modify('+2 days')))->setStatus('released');
        $cancelled = (new GameRelease($entry, $platform, 'Cancelled', $today->modify('+3 days')))->setStatus('cancelled');
        $past = new GameRelease($entry, $platform, 'Past', $today->modify('-1 day'));
        $outsideHorizon = new GameRelease($entry, $platform, 'Outside', $today->modify('+19 months'));
        $disabledGameRelease = new GameRelease($disabledGameEntry, $platform, 'Disabled game', $today->modify('+1 day'));
        $disabledEntryRelease = new GameRelease($disabledEntry, $platform, 'Disabled entry', $today->modify('+1 day'));
        $unknown = new GameRelease($entry, $platform, 'Unknown status', $today->modify('+1 day'));

        $games = [$game, $disabledGame, $disabledEntryGame];
        $entries = [$entry, $disabledGameEntry, $disabledEntry];
        $releases = [
            $first,
            $second,
            $third,
            $cancelled,
            $past,
            $outsideHorizon,
            $disabledGameRelease,
            $disabledEntryRelease,
            $unknown,
        ];
        $releaseIds = [];
        $entryIds = [];
        $gameIds = [];
        $platformId = null;

        try {
            $this->setGamingModuleEnabled($client, true);
            $entityManager = $this->entityManager($client);
            foreach ($games as $fixture) {
                $entityManager->persist($fixture);
            }
            foreach ($entries as $fixture) {
                $entityManager->persist($fixture);
            }
            $entityManager->persist($platform);
            foreach ($releases as $fixture) {
                $entityManager->persist($fixture);
            }
            $entityManager->flush();

            foreach ($releases as $fixture) {
                $releaseIds[] = $this->requireId($fixture->getId());
            }
            foreach ($entries as $fixture) {
                $entryIds[] = $this->requireId($fixture->getId());
            }
            foreach ($games as $fixture) {
                $gameIds[] = $this->requireId($fixture->getId());
            }
            $platformId = $this->requireId($platform->getId());
            $entityManager->getConnection()->executeStatement(
                'UPDATE game_catalogue_release SET status = :status WHERE id = :id',
                ['status' => 'unknown', 'id' => $this->requireId($unknown->getId())],
            );

            $feedUrl = 'http://release-feed-'.$token.'.example.test/games/releases.rss';
            $client->request('GET', $feedUrl);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                'application/rss+xml',
                (string) $client->getResponse()->headers->get('Content-Type'),
            );

            $body = $client->getResponse()->getContent();
            self::assertIsString($body);
            $xml = new \DOMDocument();
            self::assertTrue($xml->loadXML($body, LIBXML_NONET));
            self::assertSame('rss', $xml->documentElement?->tagName);
            self::assertStringContainsString('&amp;', $body);
            self::assertStringContainsString('&lt;Games&gt;', $body);

            $guidOrder = [];
            $titlesByGuid = [];
            $linksByGuid = [];
            foreach ($xml->getElementsByTagName('item') as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }

                $guid = $item->getElementsByTagName('guid')->item(0);
                $title = $item->getElementsByTagName('title')->item(0);
                $link = $item->getElementsByTagName('link')->item(0);
                if (!$guid instanceof \DOMElement || !$title instanceof \DOMElement || !$link instanceof \DOMElement) {
                    continue;
                }

                $guidOrder[] = $guid->textContent;
                $titlesByGuid[$guid->textContent] = $title->textContent;
                $linksByGuid[$guid->textContent] = $link->textContent;
            }

            $firstGuid = 'urn:gaming-cms:game-release:'.$this->requireId($first->getId());
            $secondGuid = 'urn:gaming-cms:game-release:'.$this->requireId($second->getId());
            $thirdGuid = 'urn:gaming-cms:game-release:'.$this->requireId($third->getId());
            $this->assertBefore($guidOrder, $firstGuid, $secondGuid);
            $this->assertBefore($guidOrder, $secondGuid, $thirdGuid);
            self::assertStringContainsString('WCP555 & <Games> '.$token, $titlesByGuid[$firstGuid] ?? '');
            self::assertStringStartsWith(
                'http://release-feed-'.$token.'.example.test/games/wcp555-release-'.$token,
                $linksByGuid[$firstGuid] ?? '',
            );

            foreach ([$cancelled, $past, $outsideHorizon, $disabledGameRelease, $disabledEntryRelease, $unknown] as $excluded) {
                $excludedGuid = 'urn:gaming-cms:game-release:'.$this->requireId($excluded->getId());
                self::assertNotContains($excludedGuid, $guidOrder);
            }

            $etag = $client->getResponse()->getEtag();
            self::assertNotNull($etag);

            $client->request('GET', $feedUrl, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(304);

            $alternateHostUrl = 'http://alternate-'.$token.'.example.test/games/releases.rss';
            $client->request('GET', $alternateHostUrl, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseIsSuccessful();
            self::assertNotSame($etag, $client->getResponse()->getEtag());

            $client->request('POST', '/games/releases.rss');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeCatalogueFixtures($client, $releaseIds, $entryIds, $gameIds, $platformId);
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testFeedCapsOutputAtTwoHundredItems(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);
        $token = bin2hex(random_bytes(6));
        $today = new \DateTimeImmutable('today');
        $game = (new Game())->setName('WCP555 bounded '.$token)->setSlug('wcp555-bounded-'.$token);
        $entry = new GameCatalogueEntry($game);
        $platform = new GamePlatform('PC', 'wcp555-bounded-platform-'.$token);
        /** @var list<GameRelease> $releases */
        $releases = [];
        $releaseIds = [];
        $gameId = null;
        $entryId = null;
        $platformId = null;

        try {
            $this->setGamingModuleEnabled($client, true);
            $entityManager = $this->entityManager($client);
            $entityManager->persist($game);
            $entityManager->persist($entry);
            $entityManager->persist($platform);
            for ($index = 1; $index <= 201; ++$index) {
                $release = new GameRelease(
                    $entry,
                    $platform,
                    'Region '.$index,
                    $today->modify(sprintf('+%d days', $index)),
                );
                $releases[] = $release;
                $entityManager->persist($release);
            }
            $entityManager->flush();

            $gameId = $this->requireId($game->getId());
            $entryId = $this->requireId($entry->getId());
            $platformId = $this->requireId($platform->getId());
            foreach ($releases as $release) {
                $releaseIds[] = $this->requireId($release->getId());
            }
            $lastGuid = 'urn:gaming-cms:game-release:'.$releaseIds[200];

            $client->request('GET', '/games/releases.rss');
            self::assertResponseIsSuccessful();
            $body = $client->getResponse()->getContent();
            self::assertIsString($body);
            $xml = new \DOMDocument();
            self::assertTrue($xml->loadXML($body, LIBXML_NONET));
            self::assertSame(200, $xml->getElementsByTagName('item')->length);

            $foundLastRelease = false;
            foreach ($xml->getElementsByTagName('guid') as $guid) {
                if ($guid->textContent === $lastGuid) {
                    $foundLastRelease = true;
                    break;
                }
            }
            self::assertFalse($foundLastRelease);
        } finally {
            $this->removeCatalogueFixtures(
                $client,
                $releaseIds,
                $entryId === null ? [] : [$entryId],
                $gameId === null ? [] : [$gameId],
                $platformId,
            );
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesTheFeed(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureGamingModuleState($client);

        try {
            $this->setGamingModuleEnabled($client, false);
            $client->request('GET', '/games/releases.rss');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingModuleState($client, $moduleSnapshot);
        }
    }

    /** @return array{exists: bool, enabled: bool} */
    private function captureGamingModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('gaming')) {
            self::markTestSkipped('The Gaming module must be installed for the release feed test.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'gaming');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setGamingModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        if ($state === null) {
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

    /**
     * @param list<int> $releaseIds
     * @param list<int> $entryIds
     * @param list<int> $gameIds
     */
    private function removeCatalogueFixtures(
        KernelBrowser $client,
        array $releaseIds,
        array $entryIds,
        array $gameIds,
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
        $entityManager->flush();

        foreach ($entryIds as $id) {
            $entry = $entityManager->find(GameCatalogueEntry::class, $id);
            if ($entry instanceof GameCatalogueEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        foreach ($gameIds as $id) {
            $game = $entityManager->find(Game::class, $id);
            if ($game instanceof Game) {
                $entityManager->remove($game);
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

    /** @param list<string> $orderedGuids */
    private function assertBefore(array $orderedGuids, string $first, string $second): void
    {
        $positions = array_flip($orderedGuids);
        self::assertArrayHasKey($first, $positions);
        self::assertArrayHasKey($second, $positions);
        self::assertTrue($positions[$first] < $positions[$second]);
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Persisted release feed fixture has no database ID.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
