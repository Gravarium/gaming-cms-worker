<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Competition> */
final class PublicCompetitionBracketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Competition::class);
    }

    /** @return list<Competition> */
    public function publicCompetitionsWithMatches(): array
    {
        return $this->createQueryBuilder('competition')
            ->select('DISTINCT competition')
            ->innerJoin(CompetitionMatch::class, 'bracketMatch', Join::WITH, 'bracketMatch.competition = competition')
            ->innerJoin('competition.game', 'game')
            ->andWhere('competition.visibility = :visibility')
            ->andWhere('competition.status IN (:statuses)')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('visibility', Competition::VISIBILITY_PUBLIC)
            ->setParameter('statuses', [Competition::STATUS_IN_PROGRESS, Competition::STATUS_COMPLETED])
            ->setParameter('enabled', true)
            ->orderBy('competition.startsAt', 'DESC')
            ->addOrderBy('competition.id', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();
    }
}
