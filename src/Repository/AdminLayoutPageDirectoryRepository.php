<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentEntry> */
final class AdminLayoutPageDirectoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentEntry::class);
    }

    public function countPages(string $query): int
    {
        $builder = $this->createQueryBuilder('entry')
            ->select('COUNT(entry.id)')
            ->andWhere('entry.type = :type')
            ->setParameter('type', ContentEntry::TYPE_PAGE);

        $this->applySearch($builder, $query);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /** @return list<ContentEntry> */
    public function findPages(string $query, int $limit, int $offset): array
    {
        $builder = $this->createQueryBuilder('entry')
            ->andWhere('entry.type = :type')
            ->setParameter('type', ContentEntry::TYPE_PAGE)
            ->orderBy('entry.title', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));

        $this->applySearch($builder, $query);

        return $builder->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $builder, string $query): void
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return;
        }

        $builder
            ->andWhere('(LOWER(entry.title) LIKE :search OR LOWER(entry.slug) LIKE :search)')
            ->setParameter('search', '%'.$query.'%');
    }
}
