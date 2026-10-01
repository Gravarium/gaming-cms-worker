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

final class GameReleaseArchiveTest extends WebTestCase
{
    public function testArchivePaginatesPastReleasedGamesAndExcludesNonPublicEntries(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $this->gamingState($em, true);
        $suffix = bin2hex(random_bytes(5));
        $year = (int) (new \DateTimeImmutable('today'))->format('Y') - 1;
        $client->request('GET', '/games/releases/archive', ['year' => (string) $year]);
        self::assertResponseIsSuccessful();
        $baselineText = $client->getCrawler()->filter('p[role="status"]')->text();
        self::assertSame(1, preg_match('/\A([0-9]+) veröffentlichte/', $baselineText, $matches));
        $expectedTotal = (int) $matches[1] + 21;
        $platform = new GamePlatform('Archive platform '.$suffix, 'archive-platform-'.$suffix);
        $em->persist($platform);
        $fixtures = [$platform];

        try {
            $this->releases($em, $platform, 'Archive visible '.$suffix, 'archive-visible-'.$suffix, $year, 21, true, true, 'released', $fixtures);
            $this->releases($em, $platform, 'Archive hidden game '.$suffix, 'archive-hidden-game-'.$suffix, $year, 1, false, true, 'released', $fixtures);
            $this->releases($em, $platform, 'Archive hidden entry '.$suffix, 'archive-hidden-entry-'.$suffix, $year, 1, true, false, 'released', $fixtures);
            $this->releases($em, $platform, 'Archive delayed '.$suffix, 'archive-delayed-'.$suffix, $year, 1, true, true, 'delayed', $fixtures);
            $this->releases($em, $platform, 'Archive future '.$suffix, 'archive-future-'.$suffix, $year + 1, 1, true, true, 'released', $fixtures, new \DateTimeImmutable('+1 day'));
            $em->flush();

            $client->request('GET', '/games/releases/archive', ['year' => (string) $year, 'page' => '2']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Veröffentlichte Spiele');
            self::assertSelectorTextContains('p[role="status"]', $expectedTotal.' veröffentlichte Versionen gefunden.');
            self::assertGreaterThan(0, $client->getCrawler()->filter('tr.release-row')->count());
            self::assertSelectorTextContains('nav.pagination', 'Seite 2 von '.(int) ceil($expectedTotal / 20));
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Archive hidden game '.$suffix, $body);
            self::assertStringNotContainsString('Archive hidden entry '.$suffix, $body);
            self::assertStringNotContainsString('Archive delayed '.$suffix, $body);
            self::assertStringNotContainsString('Archive future '.$suffix, $body);

            $client->request('GET', '/games/releases/archive');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Archive future '.$suffix, (string) $client->getResponse()->getContent());

            $client->request('GET', '/games/releases/calendar');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/games/releases/archive"]');
        } finally {
            $this->cleanup($em, $fixtures);
            $this->restoreGamingState($em, $module);
        }
    }

    public function testArchiveRejectsInvalidFilters(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $this->gamingState($em, true);

        try {
            $client->request('GET', '/games/releases/archive?year%5B%5D=2025');
            self::assertResponseStatusCodeSame(422);
            $client->request('GET', '/games/releases/archive', ['year' => '9999']);
            self::assertResponseStatusCodeSame(422);
            $client->request('GET', '/games/releases/archive', ['page' => '0']);
            self::assertResponseStatusCodeSame(422);
            $client->request('GET', '/games/releases/archive', ['page' => '10001']);
            self::assertResponseStatusCodeSame(422);
            $client->request('GET', '/games/releases/archive', ['page' => '9999']);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingState($em, $module);
        }
    }

    public function testDisabledGamingModuleHidesArchive(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $this->gamingState($em, false);

        try {
            $client->request('GET', '/games/releases/archive');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreGamingState($em, $module);
        }
    }

    /** @param list<object> $fixtures */
    private function releases(EntityManagerInterface $em, GamePlatform $platform, string $name, string $slug, int $year,
        int $count, bool $gameEnabled, bool $entryEnabled, string $status, array &$fixtures,
        ?\DateTimeImmutable $firstDate = null): void
    {
        $game = (new Game())->setName($name)->setSlug($slug)->setEnabled($gameEnabled);
        $entry = (new GameCatalogueEntry($game))->setEnabled($entryEnabled);
        $em->persist($game);
        $em->persist($entry);
        array_push($fixtures, $game, $entry);
        $date = $firstDate ?? new \DateTimeImmutable(sprintf('%04d-01-01 12:00:00', $year));
        for ($index = 0; $index < $count; ++$index) {
            $release = (new GameRelease($entry, $platform, 'EU', $date->modify(sprintf('+%d days', $index))))->setStatus($status);
            $em->persist($release);
            $fixtures[] = $release;
        }
    }

    /** @return array{state: CmsModuleState, previousEnabled: bool, created: bool} */
    private function gamingState(EntityManagerInterface $em, bool $enabled): array
    {
        $state = $em->getRepository(CmsModuleState::class)->findOneBy(['moduleKey' => 'gaming']);
        $created = !$state instanceof CmsModuleState;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $em->persist($state);
        }
        $previousEnabled = $state->isEnabled();
        $state->setEnabled($enabled);
        $em->flush();

        return ['state' => $state, 'previousEnabled' => $previousEnabled, 'created' => $created];
    }

    /** @param array{state: CmsModuleState, previousEnabled: bool, created: bool} $snapshot */
    private function restoreGamingState(EntityManagerInterface $em, array $snapshot): void
    {
        if ($snapshot['created']) {
            if ($em->contains($snapshot['state'])) { $em->remove($snapshot['state']); $em->flush(); }
            return;
        }
        $snapshot['state']->setEnabled($snapshot['previousEnabled']);
        $em->flush();
    }

    /** @param list<object> $fixtures */
    private function cleanup(EntityManagerInterface $em, array $fixtures): void
    {
        foreach (array_reverse($fixtures) as $entity) {
            if ($em->contains($entity)) { $em->remove($entity); }
        }
        $em->flush();
    }
}
