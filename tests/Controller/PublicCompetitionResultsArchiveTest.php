<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCompetitionResultsArchiveTest extends WebTestCase
{
    public function testHtmlAndCsvExposeConfirmedResultsOnlyAndNeutralizeFormulaNames(): void
    {
        $client = static::createClient();
        $fixture = $this->competition($client, '=HYPERLINK("https://example.test")', 2);
        $id = $fixture['competition']->getId();
        self::assertNotNull($id);

        $client->request('GET', '/competitions/'.$id.'/results/archive');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('2 : 1', $html);
        self::assertStringNotContainsString('9 : 0', $html);
        self::assertStringNotContainsString($fixture['captain']->getEmail(), $html);

        $client->request('GET', '/competitions/'.$id.'/results/archive.csv');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        $csv = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringContainsString('2,1', $csv);
        $sequenceTwo = strpos($csv, '1,winners,2,');
        $sequenceOne = strpos($csv, '1,winners,1,');
        self::assertNotFalse($sequenceTwo);
        self::assertNotFalse($sequenceOne);
        self::assertLessThan($sequenceOne, $sequenceTwo);
        self::assertStringNotContainsString('9,0', $csv);
        self::assertStringNotContainsString($fixture['captain']->getEmail(), $csv);
    }

    public function testPrivateDraftDisabledAndInvalidPagesAreUnavailableForBothFormats(): void
    {
        $client = static::createClient();
        $fixture = $this->competition($client);
        $id = $fixture['competition']->getId();
        self::assertNotNull($id);
        foreach (['0', '101', '-1', 'abc', '1.5'] as $page) {
            $client->request('GET', '/competitions/'.$id.'/results/archive?page='.$page);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/competitions/'.$id.'/results/archive.csv?page='.$page);
            self::assertResponseStatusCodeSame(404);
        }

        $entityManager = $this->em($client);
        $competition = $entityManager->find(Competition::class, $id);
        self::assertInstanceOf(Competition::class, $competition);
        $competition->setVisibility(Competition::VISIBILITY_PRIVATE);
        $entityManager->flush();
        foreach (['archive', 'archive.csv'] as $format) {
            $client->request('GET', '/competitions/'.$id.'/results/'.$format);
            self::assertResponseStatusCodeSame(404);
        }

        $entityManager = $this->em($client);
        $competition = $entityManager->find(Competition::class, $id);
        self::assertInstanceOf(Competition::class, $competition);
        $competition->setVisibility(Competition::VISIBILITY_PUBLIC);
        $game = $competition->getGame();
        self::assertInstanceOf(Game::class, $game);
        $game->setEnabled(false);
        $entityManager->flush();
        foreach (['archive', 'archive.csv'] as $format) {
            $client->request('GET', '/competitions/'.$id.'/results/'.$format);
            self::assertResponseStatusCodeSame(404);
        }

        $entityManager = $this->em($client);
        $game = $entityManager->find(Game::class, $game->getId());
        self::assertInstanceOf(Game::class, $game);
        $game->setEnabled(true);
        $draft = (new Competition())->setGame($game)->setName('Draft')->setSlug('draft-'.bin2hex(random_bytes(5)));
        $entityManager->persist($draft);
        $entityManager->flush();
        foreach (['archive', 'archive.csv'] as $format) {
            $client->request('GET', '/competitions/'.$draft->getId().'/results/'.$format);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testPageIsBoundedAndGamingGateApplies(): void
    {
        $client = static::createClient();
        $fixture = $this->competition($client, 'Alpha', 51);
        $id = $fixture['competition']->getId();
        self::assertNotNull($id);

        $client->request('GET', '/competitions/'.$id.'/results/archive');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/competitions/'.$id.'/results/archive?page=2"]');
        self::assertCount(50, $client->getCrawler()->filter('tbody tr'));
        self::assertStringContainsString('51', $client->getCrawler()->filter('tbody tr')->first()->text());

        $client->request('GET', '/competitions/'.$id.'/results/archive?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('tbody tr'));

        $entityManager = $this->em($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $created = $state === null;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();
        try {
            foreach (['archive', 'archive.csv'] as $format) {
                $client->request('GET', '/competitions/'.$id.'/results/'.$format);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            $entityManager = $this->em($client);
            $current = $entityManager->find(CmsModuleState::class, 'gaming');
            if ($created) {
                if ($current instanceof CmsModuleState) { $entityManager->remove($current); }
            } elseif ($current instanceof CmsModuleState) {
                $current->setEnabled($wasEnabled);
            }
            $entityManager->flush();
        }
    }

    /** @return array{competition: Competition, captain: User} */
    private function competition(KernelBrowser $client, string $name = 'Alpha', int $confirmedCount = 1): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Arena '.$suffix)->setSlug('arena-'.$suffix);
        $captain = (new User())->setEmail('captain-'.$suffix.'@example.test')->setDisplayName('Captain')->verifyEmail();
        $other = (new User())->setEmail('other-'.$suffix.'@example.test')->setDisplayName('Other')->verifyEmail();
        $competition = (new Competition())->setGame($game)->setCreatedBy($captain)->setName('Public Cup '.$suffix)->setSlug('cup-'.$suffix);
        $competition->open()->start();
        $a = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($captain)->setName($name);
        $b = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($other)->setName('Beta');
        $a->checkIn();
        $b->checkIn();

        foreach ([$game, $captain, $other, $competition, $a, $b] as $entity) { $entityManager->persist($entity); }
        for ($number = 1; $number <= $confirmedCount; ++$number) {
            $match = (new CompetitionMatch())->setCompetition($competition)->setSequence($number)->setParticipants($a, $b)->markReady();
            $match->submitResult($a, 2, 1, $captain);
            $match->confirmResult($b);
            $entityManager->persist($match);
        }
        $pending = (new CompetitionMatch())->setCompetition($competition)->setRoundNumber(2)->setParticipants($a, $b)->markReady();
        $pending->submitResult($a, 9, 0, $captain);
        $entityManager->persist($pending);
        $entityManager->flush();

        return ['competition' => $competition, 'captain' => $captain];
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
