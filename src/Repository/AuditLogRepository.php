<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
final class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AuditLog::class); }

    /** @return list<AuditLog> */
    public function searchAdmin(string $query, int $limit = 50, int $offset = 0): array
    {
        $builder = $this->createQueryBuilder('audit')
            ->leftJoin('audit.actor', 'actor')
            ->addSelect('actor')
            ->orderBy('audit.createdAt', 'DESC')
            ->addOrderBy('audit.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));

        if ($query !== '') {
            $builder
                ->andWhere('LOWER(audit.action) LIKE :searchQuery OR LOWER(audit.summary) LIKE :searchQuery OR LOWER(actor.email) LIKE :searchQuery')
                ->setParameter('searchQuery', '%'.mb_strtolower($query).'%');
        }

        return $builder->getQuery()->getResult();
    }

    public function countAdmin(string $query): int
    {
        $builder = $this->createQueryBuilder('audit')->select('COUNT(audit.id)');

        if ($query !== '') {
            $builder
                ->leftJoin('audit.actor', 'actor')
                ->andWhere('LOWER(audit.action) LIKE :searchQuery OR LOWER(audit.summary) LIKE :searchQuery OR LOWER(actor.email) LIKE :searchQuery')
                ->setParameter('searchQuery', '%'.mb_strtolower($query).'%');
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }
}
