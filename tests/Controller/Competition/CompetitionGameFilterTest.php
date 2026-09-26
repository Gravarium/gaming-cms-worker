<?php

declare(strict_types=1);

namespace App\Tests\Controller\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CompetitionGameFilterTest extends WebTestCase
{
    public function testGameFilterComposesWithStatusAndOnlyShowsPublicGameChoices(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);

        $crawler = $client->request('GET', '/competitions');
        self::assertResponseIsSuccessful();
        $gameSlugs = $this->gameOptionValues($crawler);
        self::assertContains($fixture['game_one_slug'], $gameSlugs);
        self::assertContains($fixture['game_two_slug'], $gameSlugs);
        foreach ($fixture['hidden_game_slugs'] as $hiddenGameSlug) {
            self::assertNotContains($hiddenGameSlug, $gameSlugs);
        }

        $crawler = $client->request('GET', '/competitions?game='.rawurlencode($fixture['game_one_slug']));
        self::assertResponseIsSuccessful();
        $titles = $this->competitionTitles($crawler);
        self::assertContains($fixture['open_title'], $titles);
        self::assertContains($fixture['completed_title'], $titles);
        self::assertNotContains($fixture['other_completed_title'], $titles);
        foreach ($fixture['hidden_titles'] as $hiddenTitle) {
            self::assertNotContains($hiddenTitle, $titles);
        }
        self::assertSame($fixture['game_one_slug'], $crawler->filter('#competition-game option[selected]')->attr('value'));
        self::assertSame(1, $crawler->filter('main a[href="/competitions"]')->count());

        $crawler = $client->request(
            'GET',
            '/competitions?game='.rawurlencode($fixture['game_one_slug']).'&status='.Competition::STATUS_COMPLETED,
        );
        self::assertResponseIsSuccessful();
        $titles = $this->competitionTitles($crawler);
        self::assertContains($fixture['completed_title'], $titles);
        self::assertNotContains($fixture['open_title'], $titles);
        self::assertNotContains($fixture['other_completed_title'], $titles);
        self::assertSame($fixture['game_one_slug'], $crawler->filter('#competition-game option[selected]')->attr('value'));
        self::assertSame(Competition::STATUS_COMPLETED, $crawler->filter('#competition-status option[selected]')->attr('value'));
    }

    public function testUnknownHiddenMalformedArrayAndOverlongGameFiltersFailClosed(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $invalidSlugs = array_merge(
            ['game-does-not-exist', str_repeat('x', 141)],
            $fixture['hidden_game_slugs'],
        );

        foreach ($invalidSlugs as $slug) {
            $client->request('GET', '/competitions?game='.rawurlencode($slug));
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('GET', '/competitions?game[]=public-game');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{
     *   game_one_slug: string,
     *   game_two_slug: string,
     *   hidden_game_slugs: list<string>,
     *   open_title: string,
     *   completed_title: string,
     *   other_completed_title: string,
     *   hidden_titles: list<string>
     * }
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $gameOne = (new Game())
            ->setName('Public Game One '.$suffix)
            ->setSlug('public-game-one-'.$suffix)
            ->setEnabled(true);
        $gameTwo = (new Game())
            ->setName('Public Game Two '.$suffix)
            ->setSlug('public-game-two-'.$suffix)
            ->setEnabled(true);
        $privateOnlyGame = (new Game())
            ->setName('Private Only Game '.$suffix)
            ->setSlug('private-only-game-'.$suffix)
            ->setEnabled(true);
        $disabledGame = (new Game())
            ->setName('Disabled Game '.$suffix)
            ->setSlug('disabled-game-'.$suffix)
            ->setEnabled(true);
        $draftOnlyGame = (new Game())
            ->setName('Draft Only Game '.$suffix)
            ->setSlug('draft-only-game-'.$suffix)
            ->setEnabled(true);

        $openTitle = 'Game One Open '.$suffix;
        $open = (new Competition())->setGame($gameOne)->setName($openTitle)->setSlug('game-one-open-'.$suffix)->open();
        $completedTitle = 'Game One Completed '.$suffix;
        $completed = (new Competition())->setGame($gameOne)->setName($completedTitle)->setSlug('game-one-completed-'.$suffix)->open()->start()->complete();
        $otherCompletedTitle = 'Game Two Completed '.$suffix;
        $otherCompleted = (new Competition())->setGame($gameTwo)->setName($otherCompletedTitle)->setSlug('game-two-completed-'.$suffix)->open()->start()->complete();
        $privateTitle = 'Private Competition '.$suffix;
        $private = (new Competition())->setGame($privateOnlyGame)->setName($privateTitle)->setSlug('private-competition-'.$suffix)
            ->setVisibility(Competition::VISIBILITY_PRIVATE)->open();
        $disabledTitle = 'Disabled Competition '.$suffix;
        $disabled = (new Competition())->setGame($disabledGame)->setName($disabledTitle)->setSlug('disabled-competition-'.$suffix)->open();
        $disabledGame->setEnabled(false);
        $draftTitle = 'Draft Competition '.$suffix;
        $draft = (new Competition())->setGame($draftOnlyGame)->setName($draftTitle)->setSlug('draft-competition-'.$suffix);

        foreach ([$gameOne, $gameTwo, $privateOnlyGame, $disabledGame, $draftOnlyGame, $open, $completed, $otherCompleted, $private, $disabled, $draft] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [
            'game_one_slug' => $gameOne->getSlug(),
            'game_two_slug' => $gameTwo->getSlug(),
            'hidden_game_slugs' => [$privateOnlyGame->getSlug(), $disabledGame->getSlug(), $draftOnlyGame->getSlug()],
            'open_title' => $openTitle,
            'completed_title' => $completedTitle,
            'other_completed_title' => $otherCompletedTitle,
            'hidden_titles' => [$privateTitle, $disabledTitle, $draftTitle],
        ];
    }

    /** @return list<string> */
    private function competitionTitles(Crawler $crawler): array
    {
        return $crawler->filter('main article h2')->each(
            static fn (Crawler $heading, int $index): string => trim($heading->text()),
        );
    }

    /** @return list<string> */
    private function gameOptionValues(Crawler $crawler): array
    {
        return $crawler->filter('#competition-game option')->each(
            static fn (Crawler $option, int $index): string => (string) $option->attr('value'),
        );
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
