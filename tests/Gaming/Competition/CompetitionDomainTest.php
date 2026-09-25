<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use App\Gaming\Competition\CompetitionBracket;
use App\Gaming\Competition\CompetitionFormat;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use PHPUnit\Framework\TestCase;

final class CompetitionDomainTest extends TestCase
{
    public function testAllSupportedFormatsHaveAStableBracketContract(): void
    {
        $formats = new CompetitionFormat();
        self::assertSame([
            Competition::FORMAT_SINGLE_ELIMINATION,
            Competition::FORMAT_DOUBLE_ELIMINATION,
            Competition::FORMAT_ROUND_ROBIN,
            Competition::FORMAT_SWISS,
            Competition::FORMAT_GROUP_STAGE,
        ], $formats->all());
        self::assertSame(CompetitionMatch::BRACKET_WINNERS, $formats->bracketFor(Competition::FORMAT_SINGLE_ELIMINATION));
        self::assertSame(CompetitionMatch::BRACKET_ROUND_ROBIN, $formats->bracketFor(Competition::FORMAT_ROUND_ROBIN));
    }

    public function testSeededPairingsAreDeterministicAndRequireTwoPlayers(): void
    {
        $game = (new Game())->setName('Arena')->setSlug('arena');
        $competition = (new Competition())->setGame($game)->setName('Cup')->setSlug('cup');
        $competition->open();
        $first = (new CompetitionParticipant())->setCompetition($competition)->setCaptain(new User())->setName('Alpha')->setSeed(2);
        $second = (new CompetitionParticipant())->setCompetition($competition)->setCaptain(new User())->setName('Bravo')->setSeed(1);
        $pairings = (new CompetitionBracket())->initialPairings($competition, [$first, $second]);
        self::assertCount(1, $pairings);
        self::assertSame($second, $pairings[0]['participantA']);
        self::assertSame($first, $pairings[0]['participantB']);

        $this->expectException(\DomainException::class);
        (new CompetitionBracket())->initialPairings($competition, [$first]);
    }

    public function testDoubleEliminationPromotionKeepsWinnerAndLoserBracketsSeparate(): void
    {
        $game = (new Game())->setName('Arena')->setSlug('arena');
        $competition = (new Competition())->setGame($game)->setName('Double Cup')->setSlug('double-cup')->setFormat(Competition::FORMAT_DOUBLE_ELIMINATION);
        $competition->open();
        $participants = [];
        for ($index = 1; $index <= 4; ++$index) {
            $participants[] = (new CompetitionParticipant())->setCompetition($competition)->setCaptain(new User())->setName('P'.$index)->setSeed($index);
        }
        $pairings = (new CompetitionBracket())->nextEliminationPairings($competition, 2, [$participants[0], $participants[1]], [$participants[2], $participants[3]]);
        self::assertCount(2, $pairings);
        self::assertSame(CompetitionMatch::BRACKET_WINNERS, $pairings[0]['bracket']);
        self::assertSame(CompetitionMatch::BRACKET_LOSERS, $pairings[1]['bracket']);
    }

    public function testCheckInAndBothSideConfirmationAreRequired(): void
    {
        $game = (new Game())->setName('Arena')->setSlug('arena');
        $competition = (new Competition())->setGame($game)->setName('Cup')->setSlug('cup');
        $competition->open();
        $userA = new User();
        $userB = new User();
        $a = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($userA)->setName('A');
        $b = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($userB)->setName('B');
        $a->checkIn();
        $b->checkIn();
        $match = (new CompetitionMatch())->setCompetition($competition)->setParticipants($a, $b)->markReady();
        $match->submitResult($a, 2, 1, $userA);
        self::assertSame(CompetitionMatch::STATUS_PENDING_CONFIRMATION, $match->getStatus());
        $match->confirmResult($b);
        self::assertSame(CompetitionMatch::STATUS_CONFIRMED, $match->getStatus());
        self::assertSame($a, $match->getWinner());
    }

    public function testDisputeMustBeExplainedAndAdminCanResolveIt(): void
    {
        $game = (new Game())->setName('Arena')->setSlug('arena');
        $competition = (new Competition())->setGame($game)->setName('Cup')->setSlug('cup');
        $competition->open();
        $userA = new User();
        $userB = new User();
        $a = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($userA)->setName('A')->checkIn();
        $b = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($userB)->setName('B')->checkIn();
        $match = (new CompetitionMatch())->setCompetition($competition)->setParticipants($a, $b)->markReady();
        $match->submitResult($a, 1, 0, $userA);
        $match->markDisputed();
        $dispute = (new CompetitionDispute())->setMatch($match)->setOpenedBy($userB)->setReason('Beleg widerspricht dem Ergebnis.');
        $admin = new User();
        $dispute->decide($admin, CompetitionDispute::STATUS_UPHELD, 'Beleg geprüft.');
        $match->resolveDispute($b, 0, 2);
        self::assertSame(CompetitionDispute::STATUS_UPHELD, $dispute->getStatus());
        self::assertSame($b, $match->getWinner());
    }

    public function testPrivateVisibilityFailsClosed(): void
    {
        $game = (new Game())->setName('Arena')->setSlug('arena');
        $competition = (new Competition())->setGame($game)->setName('Cup')->setSlug('cup')->setVisibility(Competition::VISIBILITY_PRIVATE);
        $policy = new CompetitionVisibilityPolicy();
        self::assertFalse($policy->canView($competition, null));
        self::assertFalse($policy->canView($competition, new User()));
        self::assertFalse($policy->canView($competition, $competition->getCreatedBy()));
    }
}
