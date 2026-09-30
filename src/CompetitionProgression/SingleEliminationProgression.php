<?php

declare(strict_types=1);

namespace App\CompetitionProgression;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Gaming\Competition\CompetitionBracket;
use App\Repository\Competition\CompetitionMatchRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SingleEliminationProgression
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionMatchRepository $matches,
        private CompetitionBracket $brackets,
    ) {
    }

    /** Returns the new round number, or null after the final. */
    public function advance(Competition $competition): ?int
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($competition): ?int {
            // Serialize all promotion attempts for this competition before reading fixtures.
            $entityManager->lock($competition, LockMode::PESSIMISTIC_WRITE);
            if ($competition->getStatus() !== Competition::STATUS_IN_PROGRESS
                || $competition->getFormat() !== Competition::FORMAT_SINGLE_ELIMINATION) {
                throw new \DomainException('Only active single-elimination competitions can advance.');
            }

            $all = $this->matches->forCompetition($competition);
            if ($all === []) {
                throw new \DomainException('The competition has no seeded matches.');
            }
            $round = max(array_map(static fn (CompetitionMatch $match): int => $match->getRoundNumber(), $all));
            $current = array_values(array_filter($all, static fn (CompetitionMatch $match): bool => $match->getRoundNumber() === $round));
            usort($current, static fn (CompetitionMatch $left, CompetitionMatch $right): int => $left->getSequence() <=> $right->getSequence());

            $winners = [];
            $seen = [];
            foreach ($current as $match) {
                $winner = $match->getWinner();
                $winnerId = $winner?->getId();
                if ($match->getBracket() !== CompetitionMatch::BRACKET_WINNERS
                    || !$match->isConfirmed()
                    || $winnerId === null
                    || !$winner->isActive()
                    || !$match->isParticipant($winner)
                    || isset($seen[$winnerId])) {
                    throw new \DomainException('All current-round matches need distinct confirmed winners.');
                }
                $seen[$winnerId] = true;
                $winners[] = $winner;
            }

            if (count($winners) === 1 && count($current) === 1) {
                $competition->complete();
                return null;
            }
            if (count($winners) < 2) {
                throw new \DomainException('The current round cannot form complete next-round pairings.');
            }

            $bye = count($winners) % 2 === 0 ? null : array_pop($winners);
            foreach ($this->brackets->nextEliminationPairings($competition, $round + 1, $winners) as $pairing) {
                $match = (new CompetitionMatch())
                    ->setCompetition($competition)
                    ->setRoundNumber($pairing['round'])
                    ->setBracket($pairing['bracket'])
                    ->setSequence($pairing['sequence'])
                    ->setParticipants($pairing['participantA'], $pairing['participantB']);
                $entityManager->persist($match->markReady());
            }
            if ($bye !== null) {
                $entityManager->persist((new CompetitionMatch())
                    ->setCompetition($competition)
                    ->setRoundNumber($round + 1)
                    ->setBracket(CompetitionMatch::BRACKET_WINNERS)
                    ->setSequence(intdiv(count($winners), 2) + 1)
                    ->setParticipants($bye, null)
                    ->awardSingleEliminationBye());
            }

            return $round + 1;
        });
    }
}
