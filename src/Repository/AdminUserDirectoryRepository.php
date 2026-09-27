<?php

declare(strict_types=1);

namespace App\\Repository;

use App\\Entity\\User;
use DateTimeImmutable;
use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;
use Doctrine\\ORM\\QueryBuilder;
use Doctrine\\Persistence\\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class AdminUserDirectoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function countUsers(string $query, ?string $state, DateTimeImmutable $now): int
    {
        $builder = $this->createQueryBuilder('user')->select('COUNT(user.id)');
        $this->applyFilters($builder, $query, $state, $now);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<User>
     */
    public function findUsers(
        string $query,
        ?string $state,
        DateTimeImmutable $now,
        int $limit,
        int $offset,
    ): array {
        $builder = $this->createQueryBuilder('user')
            ->orderBy('user.createdAt', 'DESC')
            ->addOrderBy('user.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));
        $this->applyFilters($builder, $query, $state, $now);

        return $builder->getQuery()->getResult();
    }

    private function applyFilters(QueryBuilder $builder, string $query, ?string $state, DateTimeImmutable $now): void
    {
        $query = mb_strtolower(trim($query));
        if ($query !== '') {
            $builder
                ->andWhere('(LOWER(user.displayName) LIKE :search OR LOWER(user.email) LIKE :search)')
                ->setParameter('search', '%'.$query.'%');
        }

        if ($state === 'active') {
            $builder
                ->andWhere('user.isActive = true')
                ->andWhere('(user.lockedUntil IS NULL OR user.lockedUntil <= :now)')
                ->setParameter('now', $now);
        } elseif ($state === 'locked') {
            $builder
                ->andWhere('(user.isActive = false OR user.lockedUntil > :now)')
                ->setParameter('now', $now);
        } elseif ($state === 'unverified') {
            $builder->andWhere('user.emailVerifiedAt IS NULL');
        }
    }
}
