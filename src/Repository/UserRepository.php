<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** @return list<User> */
    public function searchAdmin(?string $query, ?string $state): array
    {
        $builder = $this->createQueryBuilder('user')->orderBy('user.createdAt', 'DESC')->setMaxResults(250);
        $query = trim((string) $query);
        if ($query !== '') {
            $builder->andWhere('LOWER(user.email) LIKE :query OR LOWER(user.displayName) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if ($state === 'active') { $builder->andWhere('user.isActive = true')->andWhere('user.lockedUntil IS NULL OR user.lockedUntil <= :now')->setParameter('now', new \DateTimeImmutable()); }
        if ($state === 'locked') { $builder->andWhere('user.isActive = false OR user.lockedUntil > :now')->setParameter('now', new \DateTimeImmutable()); }
        if ($state === 'unverified') { $builder->andWhere('user.emailVerifiedAt IS NULL'); }

        return $builder->getQuery()->getResult();
    }
}
