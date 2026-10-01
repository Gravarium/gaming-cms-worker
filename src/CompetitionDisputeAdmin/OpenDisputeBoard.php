<?php

declare(strict_types=1);

namespace App\CompetitionDisputeAdmin;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OpenDisputeBoard
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<CompetitionDispute> */
    public function forCompetition(Competition $competition): array
    {
        /** @var list<CompetitionDispute> $disputes */
        $disputes = $this->entityManager->createQueryBuilder()
            ->select('dispute', 'match', 'participantA', 'participantB', 'openedBy')
            ->from(CompetitionDispute::class, 'dispute')
            ->innerJoin('dispute.match', 'match')
            ->innerJoin('dispute.openedBy', 'openedBy')
            ->leftJoin('match.participantA', 'participantA')
            ->leftJoin('match.participantB', 'participantB')
            ->andWhere('match.competition = :competition')
            ->andWhere('dispute.status = :open')
            ->andWhere('match.status = :disputed')
            ->setParameter('competition', $competition)
            ->setParameter('open', CompetitionDispute::STATUS_OPEN)
            ->setParameter('disputed', CompetitionMatch::STATUS_DISPUTED)
            ->orderBy('dispute.createdAt', 'DESC')
            ->addOrderBy('dispute.id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        return $disputes;
    }
}
