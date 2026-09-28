<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameReleaseCalendarFeedTest extends WebTestCase
{
    private ?EntityManagerInterface $cleanupEntityManager = null;

    /** @var list<object> */
    private array $cleanupEntities = [];

    protected function tearDown(): void
    {
        if ($this->cleanupEntityManager !== null && $this->cleanupEntityManager->isOpen()) {
            foreach (array_reverse($this->cleanupEntities) as $entity) {
                if ($this->cleanupEntityManager->contains($entity)) {
                    $this->cleanupEntityManager->remove($entity);
                }
            }
            $this->cleanupEntityManager->flush();
        }

        parent::tearDown();
    }

    public function testFeedContainsOnlyPublicUpcomingAnnouncedAndDelayedReleases(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $previousGamingState = $this->setGamingEnabled($entityManager, true);
        try {
            $suffix = bin2hex(random_bytes(5));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $visible = $this->createRelease(
                $entityManager,
                "Finale, R&D;\r\nBEGIN:VEVENT ".str_repeat('Ö', 25),
                'feed-visible-'.$suffix,
                "EU,\r\nX-ATTACK: injected",
                $now->modify('+2 days'),
                'announced',
            );
            $delayed = $this->createRelease(
                $entityManager,
                'Delayed Release '.$suffix,
                'feed-delayed-'.$suffix,
                'NA',
                $now->modify('+3 days'),
                'delayed',
            );
            $this->createRelease($entityManager, 'Cancelled Canary '.$suffix, 'feed-cancelled-'.$suffix, 'EU', $now->modify('+4 days'), 'cancelled');
            $this->createRelease($entityManager, 'Released Canary '.$suffix, 'feed-released-'.$suffix, 'EU', $now->modify('+4 days'), 'released');
            $this->createRelease($entityManager, 'Past Canary '.$suffix, 'feed-past-'.$suffix, 'EU', $now->modify('-2 days'), 'announced');
            $this->createRelease($entityManager, 'Disabled Game Canary '.$suffix, 'feed-disabled-game-'.$suffix, 'EU', $now->modify('+4 days'), 'announced', false);
            $this->createRelease($entityManager, 'Disabled Entry Canary '.$suffix, 'feed-disabled-entry-'.$suffix, 'EU', $now->modify('+4 days'), 'announced', true, false);
            $this->createRelease($entityManager, 'Outside Window Canary '.$suffix, 'feed-outside-'.$suffix, 'EU', $now->modify('+20 months'), 'announced');

            $client->request('GET', '/games/releases/calendar.ics');

            self::assertResponseIsSuccessful();
            self::assertSame('text/calendar; charset=utf-8', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('inline; filename="game-releases.ics"', $client->getResponse()->headers->get('Content-Disposition'));
            $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringNotContainsString('public', $cacheControl);
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            $body = $this->responseContent($client);
            self::assertStringContainsString('UID:game-release-'.$visible->getId().'@gaming-cms', $body);
            self::assertStringContainsString('UID:game-release-'.$delayed->getId().'@gaming-cms', $body);
            self::assertStringContainsString('SUMMARY:Finale\, R&D\;\nBEGIN:VEVENT', $body);
            self::assertStringContainsString('DTSTART:'.$visible->getReleaseAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'), $body);
            self::assertStringContainsString('\\nX-ATTACK: injected', $body);
            self::assertStringNotContainsString('Cancelled Canary '.$suffix, $body);
            self::assertStringNotContainsString('Released Canary '.$suffix, $body);
            self::assertStringNotContainsString('Past Canary '.$suffix, $body);
            self::assertStringNotContainsString('Disabled Game Canary '.$suffix, $body);
            self::assertStringNotContainsString('Disabled Entry Canary '.$suffix, $body);
            self::assertStringNotContainsString('Outside Window Canary '.$suffix, $body);
            self::assertSame(2, substr_count($body, "\r\nBEGIN:VEVENT\r\n"));

            foreach (explode("\r\n", $body) as $line) {
                if ($line !== '') {
                    self::assertLessThanOrEqual(75, strlen($line));
                }
            }
        } finally {
            $this->restoreGamingState($entityManager, $previousGamingState);
        }
    }

    public function testFeedIsBoundedToTheFirstTwoHundredUpcomingRows(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $previousGamingState = $this->setGamingEnabled($entityManager, true);
        try {
            $suffix = bin2hex(random_bytes(5));
            $game = (new Game())->setName('Bounded Release Game '.$suffix)->setSlug('feed-bounded-'.$suffix);
            $entry = new GameCatalogueEntry($game);
            $platform = new GamePlatform('Platform '.$suffix, 'feed-platform-'.$suffix);
            $entityManager->persist($game);
            $entityManager->persist($entry);
            $entityManager->persist($platform);
            $this->cleanupEntities[] = $game;
            $this->cleanupEntities[] = $entry;
            $this->cleanupEntities[] = $platform;

            $date = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
            for ($index = 0; $index < 205; ++$index) {
                $release = new GameRelease($entry, $platform, 'EU', $date->modify('+'.$index.' minutes'));
                $entityManager->persist($release);
                $this->cleanupEntities[] = $release;
            }
            $entityManager->flush();

            $client->request('GET', '/games/releases/calendar.ics');

            self::assertResponseIsSuccessful();
            $body = $this->responseContent($client);
            self::assertSame(200, substr_count($body, "\r\nBEGIN:VEVENT\r\n"));
        } finally {
            $this->restoreGamingState($entityManager, $previousGamingState);
        }
    }

    public function testFeedRouteFailsClosedWhenGamingIsDisabledAndRejectsWrites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $previousGamingState = $this->setGamingEnabled($entityManager, false);

        try {
            $client->request('GET', '/games/releases/calendar.ics');
            self::assertResponseStatusCodeSame(404);

            $client->request('POST', '/games/releases/calendar.ics');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->restoreGamingState($entityManager, $previousGamingState);
        }
    }

    private function createRelease(
        EntityManagerInterface $entityManager,
        string $gameName,
        string $gameSlug,
        string $region,
        \DateTimeImmutable $releaseAt,
        string $status,
        bool $gameEnabled = true,
        bool $entryEnabled = true,
    ): GameRelease {
        $game = (new Game())
            ->setName($gameName)
            ->setSlug($gameSlug)
            ->setEnabled($gameEnabled);
        $entry = (new GameCatalogueEntry($game))->setEnabled($entryEnabled);
        $platform = new GamePlatform('Platform '.$gameSlug, 'platform-'.$gameSlug);
        $release = (new GameRelease($entry, $platform, $region, $releaseAt))->setStatus($status);

        foreach ([$game, $entry, $platform, $release] as $entity) {
            $entityManager->persist($entity);
            $this->cleanupEntities[] = $entity;
        }
        $entityManager->flush();

        return $release;
    }

    private function setGamingEnabled(EntityManagerInterface $entityManager, bool $enabled): ?bool
    {
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('1.0.0');
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

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        $this->cleanupEntityManager = $manager;

        return $manager;
    }

    private function responseContent(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }
}
