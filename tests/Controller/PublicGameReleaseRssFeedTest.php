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

final class PublicGameReleaseRssFeedTest extends WebTestCase
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

    public function testFeedIncludesOnlyEnabledUpcomingAnnouncedAndDelayedReleases(): void
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
                'Visible RSS Release '.$suffix,
                'rss-visible-'.$suffix,
                'EU',
                $now->modify('+2 days'),
                'announced',
            );
            $delayed = $this->createRelease(
                $entityManager,
                'Delayed RSS Release '.$suffix,
                'rss-delayed-'.$suffix,
                'NA',
                $now->modify('+3 days'),
                'delayed',
            );
            $this->createRelease($entityManager, 'Cancelled RSS Canary '.$suffix, 'rss-cancelled-'.$suffix, 'EU', $now->modify('+4 days'), 'cancelled');
            $this->createRelease($entityManager, 'Released RSS Canary '.$suffix, 'rss-released-'.$suffix, 'EU', $now->modify('+4 days'), 'released');
            $this->createRelease($entityManager, 'Past RSS Canary '.$suffix, 'rss-past-'.$suffix, 'EU', $now->modify('-2 days'), 'announced');
            $this->createRelease($entityManager, 'Disabled Game RSS Canary '.$suffix, 'rss-disabled-game-'.$suffix, 'EU', $now->modify('+4 days'), 'announced', false);
            $this->createRelease($entityManager, 'Disabled Entry RSS Canary '.$suffix, 'rss-disabled-entry-'.$suffix, 'EU', $now->modify('+4 days'), 'announced', true, false);
            $this->createRelease($entityManager, 'Outside Window RSS Canary '.$suffix, 'rss-outside-'.$suffix, 'EU', $now->modify('+20 months'), 'announced');

            $client->request('GET', '/games/releases/feed.xml');

            self::assertResponseIsSuccessful();
            self::assertSame('application/rss+xml; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('inline; filename="game-releases.xml"', $client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringNotContainsString('public', $cacheControl);

            $xml = $this->responseContent($client);
            $document = $this->parseXml($xml);
            self::assertSame(2, $document->getElementsByTagName('item')->length);
            self::assertStringContainsString('Visible RSS Release '.$suffix, $xml);
            self::assertStringContainsString('Delayed RSS Release '.$suffix, $xml);
            self::assertStringNotContainsString('Cancelled RSS Canary '.$suffix, $xml);
            self::assertStringNotContainsString('Released RSS Canary '.$suffix, $xml);
            self::assertStringNotContainsString('Past RSS Canary '.$suffix, $xml);
            self::assertStringNotContainsString('Disabled Game RSS Canary '.$suffix, $xml);
            self::assertStringNotContainsString('Disabled Entry RSS Canary '.$suffix, $xml);
            self::assertStringNotContainsString('Outside Window RSS Canary '.$suffix, $xml);
            self::assertStringContainsString('game-release-'.$visible->getId().'@gaming-cms', $xml);
            self::assertStringContainsString('game-release-'.$delayed->getId().'@gaming-cms', $xml);
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
            $game = (new Game())->setName('Bounded RSS Release Game '.$suffix)->setSlug('rss-bounded-'.$suffix);
            $entry = new GameCatalogueEntry($game);
            $platform = new GamePlatform('Platform '.$suffix, 'rss-platform-'.$suffix);
            foreach ([$game, $entry, $platform] as $entity) {
                $entityManager->persist($entity);
                $this->cleanupEntities[] = $entity;
            }

            $date = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
            for ($index = 0; $index < 205; ++$index) {
                $release = new GameRelease($entry, $platform, 'EU', $date->modify('+'.$index.' minutes'));
                $entityManager->persist($release);
                $this->cleanupEntities[] = $release;
            }
            $entityManager->flush();

            $client->request('GET', '/games/releases/feed.xml');

            self::assertResponseIsSuccessful();
            $document = $this->parseXml($this->responseContent($client));
            self::assertSame(200, $document->getElementsByTagName('item')->length);
        } finally {
            $this->restoreGamingState($entityManager, $previousGamingState);
        }
    }

    public function testRouteFailsClosedWhenGamingIsDisabledAndRejectsWrites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $previousGamingState = $this->setGamingEnabled($entityManager, false);

        try {
            $client->request('GET', '/games/releases/feed.xml');
            self::assertResponseStatusCodeSame(404);

            $client->request('POST', '/games/releases/feed.xml');
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
        $platform = new GamePlatform('Platform '.$gameSlug, 'rss-platform-'.$gameSlug);
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

    private function parseXml(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($xml, LIBXML_NONET));

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
