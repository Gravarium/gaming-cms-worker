<?php

declare(strict_types=1);

namespace App\Tests\Controller\Competition;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CompetitionIndexFilterTest extends WebTestCase
{
    public function testDirectoryFiltersPublicLifecycleStatesWithoutLeakingHiddenCompetitions(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        try {

            $crawler = $client->request('GET', '/competitions');
            self::assertResponseIsSuccessful();
            $titles = $this->competitionTitles($crawler);
            foreach ($fixture['visible'] as $title) {
                self::assertContains($title, $titles);
            }
            foreach ($fixture['hidden'] as $title) {
                self::assertNotContains($title, $titles);
            }
            self::assertSame('', $crawler->filter('#competition-status option[selected]')->attr('value'));

            foreach ([
                Competition::STATUS_OPEN => $fixture['open'],
                Competition::STATUS_IN_PROGRESS => $fixture['in_progress'],
                Competition::STATUS_COMPLETED => $fixture['completed'],
                Competition::STATUS_ARCHIVED => $fixture['archived'],
            ] as $status => $expectedTitle) {
                $crawler = $client->request('GET', '/competitions?status='.rawurlencode($status));
                self::assertResponseIsSuccessful();
                $titles = $this->competitionTitles($crawler);
                self::assertContains($expectedTitle, $titles);
                foreach (array_diff($fixture['visible'], [$expectedTitle]) as $otherTitle) {
                    self::assertNotContains($otherTitle, $titles);
                }
                foreach ($fixture['hidden'] as $hiddenTitle) {
                    self::assertNotContains($hiddenTitle, $titles);
                }
                self::assertSame($status, $crawler->filter('#competition-status option[selected]')->attr('value'));
                self::assertSame(1, $crawler->filter('main a[href="/competitions"]')->count());
            }
        } finally {
            $this->cleanupFixtures($client, $fixture['cleanup_ids']);
        }

    }

    public function testDraftUnknownArrayAndOverlongFiltersFailClosed(): void
    {
        $client = static::createClient();

        foreach ([
            '/competitions?status=draft',
            '/competitions?status=unknown',
            '/competitions?status[]=open',
            '/competitions?status='.str_repeat('x', 200),
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testDirectoryStillHonorsDisabledGamingModule(): void
    {
        $client = static::createClient();
        $entityManager = $this->em($client);
        $original = $entityManager->find(CmsModuleState::class, 'gaming');
        $hadOriginalState = $original !== null;
        $wasEnabled = $original?->isEnabled() ?? true;
        $state = $original ?? (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');

        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/competitions?status=open');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $restoreManager = $this->em($client);
            $currentState = $restoreManager->find(CmsModuleState::class, 'gaming');
            if (!$hadOriginalState) {
                if ($currentState instanceof CmsModuleState) {
                    $restoreManager->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($wasEnabled);
            }
            $restoreManager->flush();
        }
    }

    /**
     * @return array{open: string, in_progress: string, completed: string, archived: string, visible: list<string>, hidden: list<string>, cleanup_ids: array{competitions: list<int>, games: list<int>}}
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Filter Game '.$suffix)
            ->setSlug('competition-filter-game-'.$suffix)
            ->setEnabled(true);
        $disabledGame = (new Game())
            ->setName('Disabled Filter Game '.$suffix)
            ->setSlug('disabled-filter-game-'.$suffix)
            ->setEnabled(true);

        $openName = 'Open '.$suffix;
        $open = (new Competition())->setGame($game)->setName($openName)->setSlug('filter-open-'.$suffix)->open();
        $inProgressName = 'In progress '.$suffix;
        $inProgress = (new Competition())->setGame($game)->setName($inProgressName)->setSlug('filter-in-progress-'.$suffix)->open()->start();
        $completedName = 'Completed '.$suffix;
        $completed = (new Competition())->setGame($game)->setName($completedName)->setSlug('filter-completed-'.$suffix)->open()->start()->complete();
        $archivedName = 'Archived '.$suffix;
        $archived = (new Competition())->setGame($game)->setName($archivedName)->setSlug('filter-archived-'.$suffix)->open()->archive();
        $draftName = 'Draft '.$suffix;
        $draft = (new Competition())->setGame($game)->setName($draftName)->setSlug('filter-draft-'.$suffix);
        $privateName = 'Private '.$suffix;
        $private = (new Competition())->setGame($game)->setName($privateName)->setSlug('filter-private-'.$suffix)
            ->setVisibility(Competition::VISIBILITY_PRIVATE)->open();
        $disabledName = 'Disabled game '.$suffix;
        $disabled = (new Competition())->setGame($disabledGame)->setName($disabledName)->setSlug('filter-disabled-'.$suffix)->open();
        $disabledGame->setEnabled(false);

        foreach ([$game, $disabledGame, $open, $inProgress, $completed, $archived, $draft, $private, $disabled] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [
            'cleanup_ids' => [
                'competitions' => [$this->requiredId($open->getId()), $this->requiredId($inProgress->getId()), $this->requiredId($completed->getId()), $this->requiredId($archived->getId()), $this->requiredId($draft->getId()), $this->requiredId($private->getId()), $this->requiredId($disabled->getId())],
                'games' => [$this->requiredId($game->getId()), $this->requiredId($disabledGame->getId())],
            ],
            'open' => $openName,
            'in_progress' => $inProgressName,
            'completed' => $completedName,
            'archived' => $archivedName,
            'visible' => [$openName, $inProgressName, $completedName, $archivedName],
            'hidden' => [$draftName, $privateName, $disabledName],
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
