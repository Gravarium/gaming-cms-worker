<?php

declare(strict_types=1);

namespace App\\Repository;

use App\\Entity\\ContentEntry;
use App\\Entity\\ContentTag;
use DateTimeImmutable;
use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;
use Doctrine\\ORM\\QueryBuilder;
use Doctrine\\Persistence\\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentEntry> */
final class PublicContentTagArchiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentEntry::class);
    }

    public function countPublicEntriesForTag(ContentTag $tag, DateTimeImmutable $now): int
    {
        return (int) $this->publicEntriesBuilder($tag, $now)
            ->select('COUNT(DISTINCT entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ContentEntry>
     */
    public function findPublicEntriesForTag(ContentTag $tag, DateTimeImmutable $now, int $limit, int $offset): array
    {
        /** @var list<ContentEntry> $entries */
        $entries = $this->publicEntriesBuilder($tag, $now)
            ->distinct()
            ->orderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $entries;
    }

    private function publicEntriesBuilder(ContentTag $tag, DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('entry')
            ->innerJoin('entry.tags', 'archiveTag')
            ->andWhere('archiveTag = :tag')
            ->andWhere('entry.status = :status')
            ->andWhere('(entry.type = :newsType OR entry.type = :pageType)')
            ->andWhere('entry.publishedAt IS NOT NULL')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = false')
            ->setParameter('tag', $tag)
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('newsType', ContentEntry::TYPE_NEWS)
            ->setParameter('pageType', ContentEntry::TYPE_PAGE)
            ->setParameter('now', $now);
    }
}
