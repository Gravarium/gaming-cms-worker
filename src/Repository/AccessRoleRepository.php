<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessRole;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccessRole> */
final class AccessRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AccessRole::class); }

    /** @return list<AccessRole> */
    public function assignable(): array { return $this->findBy(['active' => true], ['name' => 'ASC']); }

    /** @return list<User> */
    public function searchMembersForRole(AccessRole $role, string $query, string $state, int $limit = 25, int $offset = 0): array
    {
        return $this->memberQueryBuilder($role, $query, $state)
            ->orderBy('rosterUser.createdAt', 'DESC')
            ->addOrderBy('rosterUser.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countMembersForRole(AccessRole $role, string $query, string $state): int
    {
        return (int) $this->memberQueryBuilder($role, $query, $state)
            ->select('COUNT(rosterUser.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function memberQueryBuilder(AccessRole $role, string $query, string $state): QueryBuilder
    {
        $builder = $this->createQueryBuilder('accessRole')
            ->select('rosterUser')
            ->innerJoin('accessRole.users', 'rosterUser')
            ->andWhere('accessRole = :role')
            ->setParameter('role', $role);

        $query = trim($query);
        if ($query !== '') {
            $builder
                ->andWhere('(LOWER(rosterUser.displayName) LIKE :query OR LOWER(rosterUser.email) LIKE :query)')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        $now = new \DateTimeImmutable();
        if ($state === 'active') {
            $builder
                ->andWhere('rosterUser.isActive = true')
                ->andWhere('(rosterUser.lockedUntil IS NULL OR rosterUser.lockedUntil <= :now)')
                ->setParameter('now', $now);
        } elseif ($state === 'locked') {
            $builder
                ->andWhere('rosterUser.lockedUntil > :now')
                ->setParameter('now', $now);
        } elseif ($state === 'unverified') {
            $builder->andWhere('rosterUser.emailVerifiedAt IS NULL');
        }

        return $builder;
    }
}
