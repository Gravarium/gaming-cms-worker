<?php

declare(strict_types=1);

namespace App\Tests\Controller\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CompetitionModeFilterTest extends WebTestCase
{
    public function testModeFilterComposesWithPublicGameAndStatusFilters(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        try {

            $crawler = $client->request('GET', '/competitions');
            self::assertResponseIsSuccessful();

            $crawler = $client->request('GET', '/competitions?mode='.Competition::MODE_SOLO);
            self::assertResponseIsSuccessful();
            $titles = $this->competitionTitles($crawler);
            self::assertContains($fixture['solo_title'], $titles);
            self::assertNotContains($fixture['team_one_title'], $titles);
            self::assertNotContains($fixture['team_two_title'], $titles);
            foreach ($fixture['hidden_titles'] as $hiddenTitle) {
                self::assertNotContains($hiddenTitle, $titles);
            }
            self::assertSame(Competition::MODE_SOLO, $crawler->filter('#competition-mode option[selected]')->attr('value'));
            self::assertSame(1, $crawler->filter('main a[href="/competitions"]')->count());

            $crawler = $client->request(
                'GET',
                '/competitions?status=open&game='.rawurlencode($fixture['game_one_slug']).'&mode='.Competition::MODE_TEAM,
            );
            self::assertResponseIsSuccessful();
            $titles = $this->competitionTitles($crawler);
            self::assertContains($fixture['team_one_title'], $titles);
            self::assertNotContains($fixture['solo_title'], $titles);
            self::assertNotContains($fixture['team_two_title'], $titles);
            self::assertSame('open', $crawler->filter('#competition-status option[selected]')->attr('value'));
            self::assertSame($fixture['game_one_slug'], $crawler->filter('#competition-game option[selected]')->attr('value'));
            self::assertSame(Competition::MODE_TEAM, $crawler->filter('#competition-mode option[selected]')->attr('value'));
        } finally {
            $this->cleanupFixtures($client, $fixture['cleanup_ids']);
        }

    }

    public function testDraftUnknownArrayAndOverlongModeFiltersFailClosed(): void
    {
        $client = static::createClient();

        foreach ([
            '/competitions?mode=draft',
            '/competitions?mode=unknown',
            '/competitions?mode[]=team',
            '/competitions?mode='.str_repeat('x', 200),
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    /**
     * @return array{
     *   game_one_slug: string,
     *   solo_title: string,
     *   team_one_title: string,
     *   team_two_title: string,
     *   hidden_titles: list<string>,
     *   cleanup_ids: array{competitions: list<int>, games: list<int>}
     * }
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $gameOne = (new Game())
            ->setName('Mode Game One '.$suffix)
            ->setSlug('mode-game-one-'.$suffix)
            ->setEnabled(true);
        $gameTwo = (new Game())
            ->setName('Mode Game Two '.$suffix)
            ->setSlug('mode-game-two-'.$suffix)
            ->setEnabled(true);
        $privateGame = (new Game())
            ->setName('Private Mode Game '.$suffix)
            ->setSlug('private-mode-game-'.$suffix)
            ->setEnabled(true);
        $disabledGame = (new Game())
            ->setName('Disabled Mode Game '.$suffix)
            ->setSlug('disabled-mode-game-'.$suffix)
            ->setEnabled(true);
        $draftGame = (new Game())
            ->setName('Draft Mode Game '.$suffix)
            ->setSlug('draft-mode-game-'.$suffix)
            ->setEnabled(true);

        $soloTitle = 'Solo mode '.$suffix;
        $solo = (new Competition())->setGame($gameOne)->setName($soloTitle)->setSlug('solo-mode-'.$suffix)->open();
        $teamOneTitle = 'Team mode one '.$suffix;
        $teamOne = (new Competition())->setGame($gameOne)->setName($teamOneTitle)->setSlug('team-mode-one-'.$suffix)
            ->setMode(Competition::MODE_TEAM)->open();
        $teamTwoTitle = 'Team mode two '.$suffix;
        $teamTwo = (new Competition())->setGame($gameTwo)->setName($teamTwoTitle)->setSlug('team-mode-two-'.$suffix)
            ->setMode(Competition::MODE_TEAM)->open()->start()->complete();
        $privateTitle = 'Private mode '.$suffix;
        $private = (new Competition())->setGame($privateGame)->setName($privateTitle)->setSlug('private-mode-'.$suffix)
            ->setMode(Competition::MODE_TEAM)->setVisibility(Competition::VISIBILITY_PRIVATE)->open();
        $disabledTitle = 'Disabled mode '.$suffix;
        $disabled = (new Competition())->setGame($disabledGame)->setName($disabledTitle)->setSlug('disabled-mode-'.$suffix)
            ->setMode(Competition::MODE_TEAM)->open();
        $disabledGame->setEnabled(false);
        $draftTitle = 'Draft mode '.$suffix;
        $draft = (new Competition())->setGame($draftGame)->setName($draftTitle)->setSlug('draft-mode-'.$suffix)
            ->setMode(Competition::MODE_TEAM);

        foreach ([$gameOne, $gameTwo, $privateGame, $disabledGame, $draftGame, $solo, $teamOne, $teamTwo, $private, $disabled, $draft] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [
            'cleanup_ids' => [
                'competitions' => [$this->requiredId($solo->getId()), $this->requiredId($teamOne->getId()), $this->requiredId($teamTwo->getId()), $this->requiredId($private->getId()), $this->requiredId($disabled->getId()), $this->requiredId($draft->getId())],
                'games' => [$this->requiredId($gameOne->getId()), $this->requiredId($gameTwo->getId()), $this->requiredId($privateGame->getId()), $this->requiredId($disabledGame->getId()), $this->requiredId($draftGame->getId())],
            ],
            'game_one_slug' => $gameOne->getSlug(),
            'solo_title' => $soloTitle,
            'team_one_title' => $teamOneTitle,
            'team_two_title' => $teamTwoTitle,
            'hidden_titles' => [$privateTitle, $disabledTitle, $draftTitle],
        ];
    }

    /** @param array{competitions: list<int>, games: list<int>} $ids */
    private function cleanupFixtures(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->em($client);
        foreach ($ids['competitions'] as $id) {
            $competition = $entityManager->find(Competition::class, $id);
            if ($competition instanceof Competition) {
                $entityManager->remove($competition);
            }
        }
        $entityManager->flush();
        foreach ($ids['games'] as $id) {
            $game = $entityManager->find(Game::class, $id);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
        }
        $entityManager->flush();
    }

    private function requiredId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Persisted fixture is missing its id.');
        }

        return $id;
    }

    /** @return list<string> */
    private function competitionTitles(Crawler $crawler): array
    {
        return $crawler->filter('main article h2')->each(
            static fn (Crawler $heading, int $index): string => trim($heading->text()),
        );
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
