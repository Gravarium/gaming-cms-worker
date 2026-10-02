<?php

declare(strict_types=1);

namespace App\CompetitionSchedule;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminMatchSchedule
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<CompetitionMatch> */
    public function forCompetition(Competition $competition): array
    {
        /** @var list<CompetitionMatch> $matches */
        $matches = $this->entityManager->createQueryBuilder()
            ->select('match', 'participantA', 'participantB')
            ->from(CompetitionMatch::class, 'match')
            ->leftJoin('match.participantA', 'participantA')
            ->leftJoin('match.participantB', 'participantB')
            ->andWhere('match.competition = :competition')
            ->andWhere('match.status IN (:statuses)')
            ->setParameter('competition', $competition)
            ->setParameter('statuses', [CompetitionMatch::STATUS_SCHEDULED, CompetitionMatch::STATUS_READY])
            ->orderBy('match.scheduledAt', 'ASC')
            ->addOrderBy('match.roundNumber', 'ASC')
            ->addOrderBy('match.sequence', 'ASC')
            ->addOrderBy('match.id', 'ASC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        return $matches;
    }
}
