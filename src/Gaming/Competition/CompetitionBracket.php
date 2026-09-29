<?php

declare(strict_types=1);

namespace App\Gaming\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;

final class CompetitionBracket
{
    private readonly CompetitionFormat $formats;

    public function __construct(?CompetitionFormat $formats = null)
    {
        $this->formats = $formats ?? new CompetitionFormat();
    }

    /**
     * @param list<CompetitionParticipant> $participants
     * @return list<array{round:int, bracket:string, sequence:int, participantA:CompetitionParticipant, participantB:CompetitionParticipant}>
     */
    public function initialPairings(Competition $competition, array $participants): array
    {
        $active = array_values(array_filter($participants, static fn (CompetitionParticipant $participant): bool => $participant->isActive()));
        $this->formats->validateParticipantCount($competition, count($active));
        usort($active, static function (CompetitionParticipant $left, CompetitionParticipant $right): int {
            return ($left->getSeed() ?? PHP_INT_MAX) <=> ($right->getSeed() ?? PHP_INT_MAX)
                ?: ($left->getId() ?? PHP_INT_MAX) <=> ($right->getId() ?? PHP_INT_MAX);
        });

        $bracket = $this->formats->bracketFor($competition->getFormat());
        /** @var list<array{round:int, bracket:string, sequence:int, participantA:CompetitionParticipant, participantB:CompetitionParticipant}> $pairings */
        $pairings = [];
        $sequence = 1;
        for ($index = 0; $index + 1 < count($active); $index += 2) {
            $pairings[] = [
                'round' => 1,
                'bracket' => $bracket,
                'sequence' => $sequence++,
                'participantA' => $active[$index],
                'participantB' => $active[$index + 1],
            ];
        }
        if (count($active) % 2 !== 0 && $competition->getFormat() !== Competition::FORMAT_ROUND_ROBIN) {
            throw new \DomainException('This bracket format requires an even participant count after seeding.');
        }

        if ($competition->getFormat() === Competition::FORMAT_ROUND_ROBIN) {
            $pairings = [];
            $sequence = 1;
            for ($a = 0; $a < count($active); ++$a) {
                for ($b = $a + 1; $b < count($active); ++$b) {
                    $pairings[] = ['round' => 1, 'bracket' => $bracket, 'sequence' => $sequence++, 'participantA' => $active[$a], 'participantB' => $active[$b]];
                }
            }
        }

        return $pairings;
    }

    /**
     * Build the next elimination round from confirmed winners and, for double elimination,
     * the corresponding losers bracket. The method is deliberately persistence-free so a
     * future Fortress adapter can own fixture storage and promotion transactions.
     *
     * @param list<CompetitionParticipant> $winners
     * @param list<CompetitionParticipant> $losers
     * @return list<array{round:int, bracket:string, sequence:int, participantA:CompetitionParticipant, participantB:CompetitionParticipant}>
     */
    public function nextEliminationPairings(Competition $competition, int $round, array $winners, array $losers = []): array
    {
        if (!$this->formats->isElimination($competition->getFormat())) { throw new \DomainException('Only elimination competitions have winner promotion rounds.'); }
        if ($round < 2) { throw new \InvalidArgumentException('Promotion rounds start at round two.'); }
        $this->formats->validateParticipantCount($competition, count($winners));
        if (count($winners) % 2 !== 0) { throw new \DomainException('The winners bracket requires an even participant count.'); }
        if ($competition->getFormat() === Competition::FORMAT_DOUBLE_ELIMINATION && count($losers) % 2 !== 0) { throw new \DomainException('The losers bracket requires an even participant count.'); }

        $pairings = $this->pair($winners, $round, CompetitionMatch::BRACKET_WINNERS);
        if ($competition->getFormat() === Competition::FORMAT_DOUBLE_ELIMINATION) {
            $pairings = [...$pairings, ...$this->pair($losers, $round, CompetitionMatch::BRACKET_LOSERS, count($pairings) + 1)];
        }
        return $pairings;
    }

    /**
     * @param list<CompetitionParticipant> $participants
     * @return list<array{round:int, bracket:string, sequence:int, participantA:CompetitionParticipant, participantB:CompetitionParticipant}>
     */
    private function pair(array $participants, int $round, string $bracket, int $sequenceStart = 1): array
    {
        $pairings = [];
        for ($index = 0; $index + 1 < count($participants); $index += 2) {
            $pairings[] = ['round' => $round, 'bracket' => $bracket, 'sequence' => $sequenceStart + intdiv($index, 2), 'participantA' => $participants[$index], 'participantB' => $participants[$index + 1]];
        }
        return $pairings;
    }
}
