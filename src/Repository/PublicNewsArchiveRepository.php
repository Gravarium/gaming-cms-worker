<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentEntry> */
final class PublicNewsArchiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentEntry::class);
    }

    /**
     * @return list<array{year:int, month:int, count:int}>
     */
    public function availablePeriods(\DateTimeImmutable $now): array
    {
        $rows = $this->publicNewsBuilder($now)
            ->select('YEAR(entry.publishedAt) AS archiveYear')
            ->addSelect('MONTH(entry.publishedAt) AS archiveMonth')
            ->addSelect('COUNT(entry.id) AS entryCount')
            ->groupBy('YEAR(entry.publishedAt)')
            ->addGroupBy('MONTH(entry.publishedAt)')
            ->orderBy('YEAR(entry.publishedAt)', 'DESC')
            ->addOrderBy('MONTH(entry.publishedAt)', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn (array $row): array => [
                'year' => (int) $row['archiveYear'],
                'month' => (int) $row['archiveMonth'],
                'count' => (int) $row['entryCount'],
            ],
            $rows,
        ));
    }

    /**
     * @return list<ContentEntry>
     */
    public function findForPeriod(
        int $year,
        int $month,
        \DateTimeImmutable $now,
        int $offset,
        int $limit,
    ): array {
        [$start, $end] = $this->periodBounds($year, $month);

        return $this->publicNewsBuilder($now)
            ->andWhere('entry.publishedAt >= :periodStart')
            ->andWhere('entry.publishedAt < :periodEnd')
            ->setParameter('periodStart', $start)
            ->setParameter('periodEnd', $end)
            ->orderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countForPeriod(int $year, int $month, \DateTimeImmutable $now): int
    {
        [$start, $end] = $this->periodBounds($year, $month);

        return (int) $this->publicNewsBuilder($now)
            ->select('COUNT(entry.id)')
            ->andWhere('entry.publishedAt >= :periodStart')
            ->andWhere('entry.publishedAt < :periodEnd')
            ->setParameter('periodStart', $start)
            ->setParameter('periodEnd', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function publicNewsBuilder(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.type = :type')
            ->andWhere('entry.status = :status')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = false')
            ->andWhere('(entry.scheduledUnpublishAt IS NULL OR entry.scheduledUnpublishAt > :now)')
            ->setParameter('type', ContentEntry::TYPE_NEWS)
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', $now);
    }

    /**
     * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
     */
    private function periodBounds(int $year, int $month): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        return [$start, $start->modify('+1 month')];
    }
}
