<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuildApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildApplication> */
final class GuildApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildApplication::class); }

    /** @return list<GuildApplication> */
    public function findForInbox(string $query, string $status, int $limit = 25, int $offset = 0): array
    {
        return $this->inboxQueryBuilder($query, $status)
            ->orderBy('application.createdAt', 'DESC')
            ->addOrderBy('application.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countForInbox(string $query, string $status): int
    {
        return (int) $this->inboxQueryBuilder($query, $status)
            ->select('COUNT(application.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function inboxQueryBuilder(string $query, string $status): QueryBuilder
    {
        $builder = $this->createQueryBuilder('application');
        $query = trim($query);

        if ($query !== '') {
            $builder
                ->leftJoin('application.guild', 'guild')
                ->andWhere(
                    'LOWER(application.applicantName) LIKE :query'
                    .' OR LOWER(application.email) LIKE :query'
                    .' OR LOWER(application.characterName) LIKE :query'
                    .' OR LOWER(guild.name) LIKE :query',
                )
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        if ($status === 'open') {
            $builder
                ->andWhere('application.status IN (:statuses)')
                ->setParameter('statuses', [GuildApplication::STATUS_PENDING, GuildApplication::STATUS_REVIEWING]);
        } elseif (in_array($status, [GuildApplication::STATUS_ACCEPTED, GuildApplication::STATUS_REJECTED], true)) {
            $builder
                ->andWhere('application.status = :status')
                ->setParameter('status', $status);
        }

        return $builder;
    }
}
