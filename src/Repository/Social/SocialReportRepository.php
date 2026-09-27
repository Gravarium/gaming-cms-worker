<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialReport> */
final class SocialReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialReport::class);
    }

    /** @return list<SocialReport> */
    public function openQueue(int $limit = 100): array
    {
        return $this->createQueryBuilder('report')
            ->andWhere('report.status IN (:states)')
            ->setParameter('states', [SocialReport::STATUS_OPEN, SocialReport::STATUS_REVIEWING])
            ->orderBy('report.createdAt', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
