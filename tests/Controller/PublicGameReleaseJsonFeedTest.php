<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameReleaseJsonFeedTest extends WebTestCase
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

    public function testFeedPublishesOnlyEligibleUpcomingReleasesAsJsonFeed11(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->entityManager($client);
        $previousGamingState = $this->setGamingEnabled($entityManager, true);

        try {
            $suffix = bin2hex(random_bytes(5));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $visible = $this->createRelease($entityManager, 'Visible JSON Release '.$suffix, 'json-visible-'.$suffix, 'EU', $now->modify('+2 days'));
            $this->createRelease($entityManager, 'Cancelled JSON Canary '.$suffix, 'json-cancelled-'.$suffix, 'EU', $now->modify('+3 days'), 'cancelled');
            $this->createRelease($entityManager, 'Disabled JSON Canary '.$suffix, 'json-disabled-'.$suffix, 'EU', $now->modify('+3 days'), 'announced', false);

            $client->request('GET', '/games/releases/feed.json');

            self::assertResponseIsSuccessful();
            self::assertSame('application/feed+json; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('inline; filename="game-releases.json"', $client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            $document = json_decode($this->responseContent($client), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('https://jsonfeed.org/version/1.1', $document['version']);
            self::assertCount(1, $document['items']);
            self::assertSame('game-release-'.$visible->getId().'@gaming-cms', $document['items'][0]['id']);
            self::assertSame('Visible JSON Release '.$suffix.' - Platform json-visible-'.$suffix.' (EU)', $document['items'][0]['title']);
            self::assertStringContainsString('json-visible-'.$suffix, $document['items'][0]['url']);
            self::assertSame('Platform: Platform json-visible-'.$suffix.'; Region: EU', $document['items'][0]['content_text']);
            self::assertArrayNotHasKey('content_html', $document['items'][0]);
            self::assertStringNotContainsString('Cancelled JSON Canary', $this->responseContent($client));
            self::assertStringNotContainsString('Disabled JSON Canary', $this->responseContent($client));
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
            $client->request('GET', '/games/releases/feed.json');
            self::assertResponseStatusCodeSame(404);

            $client->request('POST', '/games/releases/feed.json');
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
        string $status = 'announced',
        bool $gameEnabled = true,
    ): GameRelease {
        $game = (new Game())->setName($gameName)->setSlug($gameSlug)->setEnabled($gameEnabled);
        $entry = new GameCatalogueEntry($game);
        $platform = new GamePlatform('Platform '.$gameSlug, 'json-platform-'.$gameSlug);
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
        if ($state instanceof CmsModuleState) {
            $state->setEnabled($previous ?? true);
            $entityManager->flush();
        }
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->cleanupEntityManager = $entityManager;

        return $entityManager;
    }

    private function responseContent(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }
}
