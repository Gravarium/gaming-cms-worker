<?php

declare(strict_types=1);

namespace App\Gaming\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;

final class CompetitionBracket
{
    public function __construct(private readonly CompetitionFormat $formats = new CompetitionFormat()) {}

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
        if (count($active) % 2 !== 0 && $this->formats->isElimination($competition->getFormat())) {
            throw new \DomainException('Elimination brackets require an even participant count after seeding.');
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
}
