<?php

declare(strict_types=1);

namespace App\Widget\CompetitionStandings;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Repository\Competition\CompetitionMatchRepository;
use App\Repository\Competition\CompetitionParticipantRepository;

final readonly class CompetitionStandingsQuery
{
    public function __construct(
        private CompetitionParticipantRepository $participants,
        private CompetitionMatchRepository $matches,
    ) {
    }

    /**
     * @return array{
     *     standings: list<array{
     *         participant: CompetitionParticipant,
     *         played: int,
     *         wins: int,
     *         draws: int,
     *         losses: int,
     *         scoreFor: int,
     *         scoreAgainst: int,
     *         difference: int,
     *         points: int
     *     }>,
     *     results: list<array{
     *         round: int,
     *         bracket: string,
     *         sequence: int,
     *         participantA: string,
     *         participantB: string,
     *         scoreA: int,
     *         scoreB: int
     *     }>
     * }
     */
    public function forCompetition(Competition $competition): array
    {
        /**
         * @var array<int, array{
         *     participant: CompetitionParticipant,
         *     played: int,
         *     wins: int,
         *     draws: int,
         *     losses: int,
         *     scoreFor: int,
         *     scoreAgainst: int,
         *     difference: int,
         *     points: int
         * }> $standings
         */
        $standings = [];
        foreach ($this->participants->forCompetition($competition) as $participant) {
            $participantId = $participant->getId();
            if ($participantId === null || !$participant->isActive()) {
                continue;
            }

            $standings[$participantId] = [
                'participant' => $participant,
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'scoreFor' => 0,
                'scoreAgainst' => 0,
                'difference' => 0,
                'points' => 0,
            ];
        }

        /** @var list<array{round: int, bracket: string, sequence: int, participantA: string, participantB: string, scoreA: int, scoreB: int}> $results */
        $results = [];
        foreach ($this->matches->forCompetition($competition) as $match) {
            $participantA = $match->getParticipantA();
            $participantB = $match->getParticipantB();
            $scoreA = $match->getScoreA();
            $scoreB = $match->getScoreB();
            $participantAId = $participantA?->getId();
            $participantBId = $participantB?->getId();
            if (!$match->isConfirmed()
                || !$participantA instanceof CompetitionParticipant
                || !$participantB instanceof CompetitionParticipant
                || $scoreA === null
                || $scoreB === null
                || $participantAId === null
                || $participantBId === null
                || !isset($standings[$participantAId], $standings[$participantBId])
            ) {
                continue;
            }

            $standingA = $standings[$participantAId];
            $standingB = $standings[$participantBId];

            ++$standingA['played'];
            ++$standingB['played'];
            $standingA['scoreFor'] += $scoreA;
            $standingA['scoreAgainst'] += $scoreB;
            $standingB['scoreFor'] += $scoreB;
            $standingB['scoreAgainst'] += $scoreA;
            $standingA['difference'] += $scoreA - $scoreB;
            $standingB['difference'] += $scoreB - $scoreA;

            if ($scoreA > $scoreB) {
                ++$standingA['wins'];
                ++$standingB['losses'];
                $standingA['points'] += 3;
            } elseif ($scoreB > $scoreA) {
                ++$standingB['wins'];
                ++$standingA['losses'];
                $standingB['points'] += 3;
            } else {
                ++$standingA['draws'];
                ++$standingB['draws'];
                ++$standingA['points'];
                ++$standingB['points'];
            }

            $standings[$participantAId] = $standingA;
            $standings[$participantBId] = $standingB;

            $results[] = [
                'round' => $match->getRoundNumber(),
                'bracket' => $match->getBracket(),
                'sequence' => $match->getSequence(),
                'participantA' => $participantA->getName(),
                'participantB' => $participantB->getName(),
                'scoreA' => $scoreA,
                'scoreB' => $scoreB,
            ];
        }

        $standings = array_values($standings);
        usort($standings, static function (array $left, array $right): int {
            foreach (['points', 'wins', 'difference', 'scoreFor'] as $key) {
                $comparison = $right[$key] <=> $left[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return strcasecmp($left['participant']->getName(), $right['participant']->getName());
        });

        return ['standings' => $standings, 'results' => $results];
    }
}
