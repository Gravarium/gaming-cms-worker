<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
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
        /** @var iterable<array{publishedAt:mixed}> $rows */
        $rows = $this->publicNewsBuilder($now)
            ->select('entry.publishedAt AS publishedAt')
            ->orderBy('entry.publishedAt', 'DESC')
            ->getQuery()
            ->toIterable([], AbstractQuery::HYDRATE_SCALAR);

        /** @var array<string, array{year:int, month:int, count:int}> $periods */
        $periods = [];
        foreach ($rows as $row) {
            $publishedAt = $row['publishedAt'];
            if ($publishedAt instanceof \DateTimeInterface) {
                $date = $publishedAt;
            } elseif (is_string($publishedAt)) {
                $date = new \DateTimeImmutable($publishedAt);
            } else {
                continue;
            }

            $key = $date->format('Y-m');
            if (!isset($periods[$key])) {
                $periods[$key] = [
                    'year' => (int) $date->format('Y'),
                    'month' => (int) $date->format('n'),
                    'count' => 0,
                ];
            }
            ++$periods[$key]['count'];
        }

        return array_values($periods);
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
