<?php

declare(strict_types=1);

namespace App\CompetitionDisputeQueue;

use App\Entity\Competition\CompetitionDispute;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OpenDisputeQueue
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<CompetitionDispute> */
    public function recent(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('dispute', 'match', 'competition')
            ->from(CompetitionDispute::class, 'dispute')
            ->innerJoin('dispute.match', 'match')
            ->innerJoin('match.competition', 'competition')
            ->where('dispute.status = :status')
            ->setParameter('status', CompetitionDispute::STATUS_OPEN)
            ->orderBy('dispute.createdAt', 'DESC')
            ->addOrderBy('dispute.id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }
}
