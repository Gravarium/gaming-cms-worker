<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Catalogue;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameReleaseCalendarControllerTest extends WebTestCase
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

    public function testExistingReleaseOverviewLinksToFilteredCalendar(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->entityManager($client);
        $moduleState = $this->setGamingEnabled($em, true);

        try {
            $client->request('GET', '/games/releases');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/games/releases/calendar"]');
            self::assertSelectorTextContains('header.page-header', 'Release-Kalender durchsuchen und filtern');
        } finally {
            $this->restoreGamingState($em, $moduleState);
        }
    }

    public function testSearchFiltersAndPaginatesUpcomingPublicReleases(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->entityManager($client);
        $moduleState = $this->setGamingEnabled($em, true);
        $token = bin2hex(random_bytes(6));
        $year = (int) (new \DateTimeImmutable('today'))->format('Y') + 1;
        $region = 'EU-'.$token;
        $fixture = $this->createFixture($em, 'Calendar Game '.$token, $token, $region, $year, 21);

        try {
            $client->request('GET', '/games/releases/calendar', [
                'q' => 'calendar game '.$token,
                'platform' => (string) $fixture['platform']->getId(),
                'region' => $region,
                'status' => 'announced',
                'year' => (string) $year,
                'page' => '2',
            ]);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Release-Kalender entdecken');
            self::assertSelectorTextContains('p[role="status"]', '21 Veröffentlichungen gefunden.');
            self::assertSelectorTextContains('nav.pagination', 'Seite 2 von 2');
            self::assertSelectorCount(1, 'tr.release-row');

            $href = $client->getCrawler()->filter('nav.pagination a')->first()->attr('href');
            self::assertIsString($href);
            $query = (string) parse_url($href, PHP_URL_QUERY);
            parse_str($query, $parameters);
            self::assertSame('calendar game '.$token, $parameters['q'] ?? null);
            self::assertSame((string) $fixture['platform']->getId(), (string) ($parameters['platform'] ?? ''));
            self::assertSame($region, $parameters['region'] ?? null);
            self::assertSame('announced', $parameters['status'] ?? null);
            self::assertSame((string) $year, (string) ($parameters['year'] ?? ''));
            self::assertSame('1', (string) ($parameters['page'] ?? ''));
        } finally {
            $this->restoreGamingState($em, $moduleState);
        }
    }

    public function testDisabledEntriesGamesAndCancelledReleasesAreNotPubliclyListed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->entityManager($client);
        $moduleState = $this->setGamingEnabled($em, true);
        $token = bin2hex(random_bytes(6));
        $year = (int) (new \DateTimeImmutable('today'))->format('Y') + 1;

        $this->createFixture($em, 'Visibility '.$token.' Public', 'visible-'.$token, 'EU-'.$token, $year, 1);
        $this->createFixture($em, 'Visibility '.$token.' DisabledGame', 'disabled-game-'.$token, 'EU-'.$token, $year, 1, false);
        $this->createFixture($em, 'Visibility '.$token.' DisabledEntry', 'disabled-entry-'.$token, 'EU-'.$token, $year, 1, true, false);
        $this->createFixture($em, 'Visibility '.$token.' Cancelled', 'cancelled-'.$token, 'EU-'.$token, $year, 1, true, true, 'cancelled');

        try {
            $client->request('GET', '/games/releases/calendar', ['q' => 'Visibility '.$token, 'year' => (string) $year]);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('p[role="status"]', '1 Veröffentlichung gefunden.');
            self::assertSelectorTextContains('tr.release-row', 'Visibility '.$token.' Public');
            $body = $client->getCrawler()->filter('body')->text();
            self::assertStringNotContainsString('DisabledGame', $body);
            self::assertStringNotContainsString('DisabledEntry', $body);
            self::assertStringNotContainsString('Cancelled', $body);
        } finally {
            $this->restoreGamingState($em, $moduleState);
        }
    }

    public function testInvalidAndOversizedFiltersAreRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->entityManager($client);
        $moduleState = $this->setGamingEnabled($em, true);

        try {
            $client->request('GET', '/games/releases/calendar', ['status' => 'private']);
            self::assertResponseStatusCodeSame(422);

            $client->request('GET', '/games/releases/calendar', ['platform' => '2147483648']);
            self::assertResponseStatusCodeSame(422);

            $client->request('GET', '/games/releases/calendar', ['q' => str_repeat('x', 101)]);
            self::assertResponseStatusCodeSame(422);

            $client->request('GET', '/games/releases/calendar?q%5B%5D=not-a-string');
            self::assertResponseStatusCodeSame(422);
        } finally {
            $this->restoreGamingState($em, $moduleState);
        }
    }

    public function testDisabledGamingModuleFailsClosed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->entityManager($client);
        $moduleState = $this->setGamingEnabled($em, false);

        try {
            $client->request('GET', '/games/releases/calendar');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingState($em, $moduleState);
        }
    }

    /**
     * @return array{game: Game, entry: GameCatalogueEntry, platform: GamePlatform, releases: list<GameRelease>}
     */
    private function createFixture(
        EntityManagerInterface $em,
        string $gameName,
        string $token,
        string $region,
        int $year,
        int $releaseCount,
        bool $gameEnabled = true,
        bool $entryEnabled = true,
        string $status = 'announced',
    ): array {
        $game = (new Game())->setName($gameName)->setSlug('calendar-'.$token)->setEnabled($gameEnabled);
        $entry = new GameCatalogueEntry($game);
        $entry->setEnabled($entryEnabled);
        $platform = new GamePlatform('Platform '.$token, 'platform-'.$token);
        $em->persist($game);
        $em->persist($entry);
        $em->persist($platform);
        $this->cleanupEntities[] = $game;
        $this->cleanupEntities[] = $entry;
        $this->cleanupEntities[] = $platform;

        $releases = [];
        $date = new \DateTimeImmutable(sprintf('%04d-01-01 12:00:00', $year));
        for ($index = 0; $index < $releaseCount; ++$index) {
            $release = new GameRelease($entry, $platform, $region, $date->modify(sprintf('+%d days', $index)));
            $release->setStatus($status);
            $em->persist($release);
            $this->cleanupEntities[] = $release;
            $releases[] = $release;
        }

        $em->flush();

        return ['game' => $game, 'entry' => $entry, 'platform' => $platform, 'releases' => $releases];
    }

    /**
     * @return array{state: CmsModuleState, previousEnabled: bool, created: bool}
     */
    private function setGamingEnabled(EntityManagerInterface $em, bool $enabled): array
    {
        $state = $em->getRepository(CmsModuleState::class)->findOneBy(['moduleKey' => 'gaming']);
        $created = !$state instanceof CmsModuleState;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $em->persist($state);
            $this->cleanupEntities[] = $state;
        }

        $previousEnabled = $state->isEnabled();
        $state->setEnabled($enabled);
        $em->flush();

        return ['state' => $state, 'previousEnabled' => $previousEnabled, 'created' => $created];
    }

    /**
     * @param array{state: CmsModuleState, previousEnabled: bool, created: bool} $snapshot
     */
    private function restoreGamingState(EntityManagerInterface $em, array $snapshot): void
    {
        if ($snapshot['created']) {
            if ($em->contains($snapshot['state'])) {
                $em->remove($snapshot['state']);
                $em->flush();
            }

            return;
        }

        $snapshot['state']->setEnabled($snapshot['previousEnabled']);
        $em->flush();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        $this->cleanupEntityManager = $manager;

        return $manager;
    }
}
