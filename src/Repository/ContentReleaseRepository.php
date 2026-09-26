<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentRelease> */
final class ContentReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentRelease::class);
    }

    /** @return list<ContentRelease> */
    public function findPublicPublished(int $limit, int $offset, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('release')
            ->andWhere('release.status = :status')
            ->andWhere('release.publishedAt <= :now')
            ->setParameter('status', ContentRelease::STATUS_PUBLISHED)
            ->setParameter('now', $now)
            ->orderBy('release.publishedAt', 'DESC')
            ->addOrderBy('release.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countPublicPublished(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('release')
            ->select('COUNT(release.id)')
            ->andWhere('release.status = :status')
            ->andWhere('release.publishedAt <= :now')
            ->setParameter('status', ContentRelease::STATUS_PUBLISHED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPublicPublishedById(int $id, \DateTimeImmutable $now): ?ContentRelease
    {
        $release = $this->createQueryBuilder('release')
            ->andWhere('release.id = :id')
            ->andWhere('release.status = :status')
            ->andWhere('release.publishedAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('status', ContentRelease::STATUS_PUBLISHED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();

        return $release instanceof ContentRelease ? $release : null;
    }

    public function countPublicEntriesForRelease(int $releaseId, \DateTimeImmutable $now): int
    {
        return (int) $this->publicEntriesBuilder($releaseId, $now)
            ->select('COUNT(DISTINCT entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ContentEntry> */
    public function findPublicEntriesForRelease(int $releaseId, int $limit, int $offset, \DateTimeImmutable $now): array
    {
        /** @var list<ContentEntry> $entries */
        $entries = $this->publicEntriesBuilder($releaseId, $now)
            ->select('entry')
            ->orderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $entries;
    }

    private function publicEntriesBuilder(int $releaseId, \DateTimeImmutable $now): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('release')
            ->join('release.entries', 'entry')
            ->andWhere('release.id = :releaseId')
            ->andWhere('release.status = :releaseStatus')
            ->andWhere('release.publishedAt <= :now')
            ->andWhere('entry.status = :entryStatus')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = :listed')
            ->setParameter('releaseId', $releaseId)
            ->setParameter('releaseStatus', ContentRelease::STATUS_PUBLISHED)
            ->setParameter('entryStatus', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('listed', false)
            ->setParameter('now', $now);
    }

    /** @return list<ContentRelease> */
    public function findDue(\DateTimeImmutable $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('release')
            ->andWhere('release.status = :status')
            ->andWhere('release.scheduledAt <= :now')
            ->setParameter('status', ContentRelease::STATUS_SCHEDULED)
            ->setParameter('now', $now)
            ->orderBy('release.scheduledAt', 'ASC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }
}
