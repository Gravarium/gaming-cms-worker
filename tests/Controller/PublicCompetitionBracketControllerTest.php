<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatchEvidence;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCompetitionBracketControllerTest extends WebTestCase
{
    public function testPublicBracketGroupsRoundsAndShowsOnlyConfirmedResults(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $game = $this->createGame($em);
        $competition = $this->createCompetition($em, $game, 'Bracket 공개 Cup');

        $userA = $this->createUser('alpha');
        $userB = $this->createUser('bravo');
        $emailA = $userA->getEmail();
        $emailB = $userB->getEmail();
        $participantA = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($userA)->setName('Alpha');
        $participantB = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($userB)->setName('Bravo');
        $participantA->checkIn();
        $participantB->checkIn();

        $em->persist($userA);
        $em->persist($userB);
        $em->persist($participantA);
        $em->persist($participantB);

        $confirmedOpening = $this->createMatch($em, $competition, $participantA, $participantB, $userA, 1, CompetitionMatch::BRACKET_WINNERS, 2, 1, true);
        $pendingLosersMatch = $this->createMatch($em, $competition, $participantA, $participantB, $userA, 1, CompetitionMatch::BRACKET_LOSERS, 9, 0, false);
        $confirmedFinal = $this->createMatch($em, $competition, $participantA, $participantB, $userA, 2, CompetitionMatch::BRACKET_WINNERS, 3, 2, true);
        self::assertTrue($confirmedOpening->isConfirmed());
        self::assertFalse($pendingLosersMatch->isConfirmed());
        self::assertTrue($confirmedFinal->isConfirmed());
        $pendingLosersMatch->markDisputed();
        $em->persist((new CompetitionDispute())
            ->setMatch($pendingLosersMatch)
            ->setOpenedBy($userB)
            ->setReason('PRIVATE_DISPUTE_REASON_SENTINEL'));
        $em->persist((new CompetitionMatchEvidence())
            ->setMatch($pendingLosersMatch)
            ->setSubmittedBy($userB)
            ->setLocator('https://evidence.example.test/private-proof-sentinel'));

        $em->flush();
        self::assertNotNull($competition->getId());

        $client->request('GET', '/competitions/'.$competition->getId().'/bracket');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bracket 공개 Cup');
        self::assertSelectorTextContains('#bracket-winners', 'Siegerbaum');
        self::assertSelectorTextContains('#bracket-losers', 'Verliererbaum');
        $html = (string) $client->getResponse()->getContent();
        $winnerPosition = strpos($html, 'id="bracket-winners"');
        $loserPosition = strpos($html, 'id="bracket-losers"');
        if ($winnerPosition === false || $loserPosition === false) {
            self::fail('Both bracket sections should be rendered.');
        }
        self::assertTrue($winnerPosition < $loserPosition, 'The winners bracket should appear before the losers bracket.');
        self::assertStringContainsString('Runde 1', $html);
        self::assertStringContainsString('Runde 2', $html);
        self::assertStringContainsString('Ergebnis: 2 : 1', $html);
        self::assertStringContainsString('Ergebnis: 3 : 2', $html);
        self::assertStringNotContainsString('9 : 0', $html);
        self::assertStringNotContainsString('PRIVATE_DISPUTE_REASON_SENTINEL', $html);
        self::assertStringNotContainsString('https://evidence.example.test/private-proof-sentinel', $html);
        self::assertStringNotContainsString($emailA, $html);
        self::assertStringNotContainsString($emailB, $html);
    }

    public function testCreatorCanViewPrivateBracketWithoutCachingOrIndexing(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $game = $this->createGame($em);
        $owner = $this->createUser('private-owner');
        $em->persist($owner);
        $competition = $this->createCompetition($em, $game, 'Private owner bracket', false);
        $competition->setCreatedBy($owner);
        $em->flush();
        self::assertNotNull($competition->getId());
        $client->loginUser($owner);

        $client->request('GET', '/competitions/'.$competition->getId().'/bracket');

        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;
        $cacheControl = (string) $headers->get('Cache-Control', '');
        self::assertTrue($headers->hasCacheControlDirective('private'), 'Cache-Control must remain private. Actual: '.$cacheControl);
        self::assertTrue($headers->hasCacheControlDirective('no-store'), 'Cache-Control must prevent storage. Actual: '.$cacheControl);
        self::assertSame('0', (string) $headers->getCacheControlDirective('max-age'), 'Cache-Control must expire immediately. Actual: '.$cacheControl);
        self::assertSame('noindex, nofollow, noarchive', (string) $headers->get('X-Robots-Tag', ''));
    }

    public function testEmptyBracketIsExplainedAndHiddenCompetitionsReturnNotFound(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $game = $this->createGame($em);
        $empty = $this->createCompetition($em, $game, 'Empty bracket');
        $private = $this->createCompetition($em, $game, 'Private bracket', false);
        $draft = (new Competition())->setGame($game)->setName('Draft bracket')->setSlug($this->slug());
        $em->persist($draft);

        $disabledGame = $this->createGame($em);
        $disabled = $this->createCompetition($em, $disabledGame, 'Disabled game bracket');
        $disabledGame->setEnabled(false);

        $em->flush();

        $client->request('GET', '/competitions/'.$empty->getId().'/bracket');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Für dieses Turnier gibt es noch keine Paarungen');

        foreach ([$private, $draft, $disabled] as $competition) {
            $client->request('GET', '/competitions/'.$competition->getId().'/bracket');
            self::assertResponseStatusCodeSame(404);
        }
    }

    private function createGame(EntityManagerInterface $em): Game
    {
        $game = (new Game())->setName('Bracket game')->setSlug($this->slug());
        $em->persist($game);

        return $game;
    }

    private function createCompetition(EntityManagerInterface $em, Game $game, string $name, bool $public = true): Competition
    {
        $competition = (new Competition())
            ->setGame($game)
            ->setName($name)
            ->setSlug($this->slug())
            ->setStartsAt(new \DateTimeImmutable('2200-01-01 00:00:00 UTC'));

        if (!$public) {
            $competition->setVisibility(Competition::VISIBILITY_PRIVATE);
        }

        $competition->open()->start();
        $em->persist($competition);

        return $competition;
    }

    private function createUser(string $label): User
    {
        return (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName(ucfirst($label))
            ->verifyEmail();
    }

    private function createMatch(
        EntityManagerInterface $em,
        Competition $competition,
        CompetitionParticipant $participantA,
        CompetitionParticipant $participantB,
        User $userA,
        int $round,
        string $bracket,
        int $scoreA,
        int $scoreB,
        bool $confirmed,
    ): CompetitionMatch {
        $match = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setRoundNumber($round)
            ->setBracket($bracket)
            ->setSequence(1)
            ->setParticipants($participantA, $participantB)
            ->setScheduledAt(new \DateTimeImmutable('2030-01-01 18:00:00 UTC'))
            ->markReady()
            ->submitResult($participantA, $scoreA, $scoreB, $userA);

        if ($confirmed) {
            $match->confirmResult($participantB);
        }

        $em->persist($match);

        return $match;
    }

    private function slug(): string
    {
        return 'bracket-'.bin2hex(random_bytes(8));
    }
}
