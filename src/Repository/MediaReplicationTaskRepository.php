<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaReplicationTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MediaReplicationTask> */
final class MediaReplicationTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaReplicationTask::class);
    }

    /** @return list<MediaReplicationTask> */
    public function pending(int $limit = 100): array
    {
        return $this->createQueryBuilder('task')
            ->addOrderBy('task.createdAt', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> */
    public function countsByTarget(): array
    {
        $rows = $this->createQueryBuilder('task')
            ->select('task.targetKey AS targetKey, COUNT(task.id) AS pendingCount')
            ->groupBy('task.targetKey')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['targetKey']] = (int) $row['pendingCount'];
        }

        return $counts;
    }
}
