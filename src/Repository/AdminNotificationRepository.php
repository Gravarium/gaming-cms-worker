<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminNotification;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminNotification> */
final class AdminNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AdminNotification::class); }
    public function unreadCount(): int { return $this->count(['readAt' => null]); }

    /** @return list<AdminNotification> */
    public function searchHistory(string $query, string $state, int $limit = 25, int $offset = 0): array
    {
        $builder = $this->createQueryBuilder('notification')
            ->orderBy('notification.createdAt', 'DESC')
            ->addOrderBy('notification.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));

        $this->applyHistoryFilters($builder, $query, $state);

        return $builder->getQuery()->getResult();
    }

    public function countHistory(string $query, string $state): int
    {
        $builder = $this->createQueryBuilder('notification')->select('COUNT(notification.id)');
        $this->applyHistoryFilters($builder, $query, $state);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    private function applyHistoryFilters(QueryBuilder $builder, string $query, string $state): void
    {
        $query = trim($query);
        if ($query !== '') {
            $builder
                ->andWhere('LOWER(notification.title) LIKE :query OR LOWER(notification.message) LIKE :query OR LOWER(notification.type) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        if ($state === 'unread') {
            $builder->andWhere('notification.readAt IS NULL');
        } elseif ($state === 'read') {
            $builder->andWhere('notification.readAt IS NOT NULL');
        }
    }
}
