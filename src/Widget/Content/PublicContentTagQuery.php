<?php

declare(strict_types=1);

namespace App\Widget\Content;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicContentTagQuery
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_ITEMS = 12;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<ContentTag>
     */
    public function findPublic(int $limit = self::DEFAULT_LIMIT): array
    {
        /** @var list<ContentTag> $tags */
        $tags = $this->visibleTagsBuilder(new \DateTimeImmutable())
            ->orderBy('tag.name', 'ASC')
            ->addOrderBy('tag.id', 'ASC')
            ->setMaxResults(max(1, min(self::MAX_ITEMS, $limit)))
            ->getQuery()
            ->getResult();

        return $tags;
    }

    public function countPublic(): int
    {
        return (int) $this->visibleTagsBuilder(new \DateTimeImmutable())
            ->select('COUNT(DISTINCT tag.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ContentTag>
     */
    public function findPublicPage(int $limit, int $offset): array
    {
        /** @var list<ContentTag> $tags */
        $tags = $this->visibleTagsBuilder(new \DateTimeImmutable())
            ->orderBy('tag.name', 'ASC')
            ->addOrderBy('tag.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(self::MAX_PAGE_SIZE, $limit)))
            ->getQuery()
            ->getResult();

        return $tags;
    }

    private function visibleTagsBuilder(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('DISTINCT tag')
            ->from(ContentTag::class, 'tag')
            ->innerJoin('tag.entries', 'entry')
            ->andWhere('entry.status = :status')
            ->andWhere('(entry.type = :newsType OR entry.type = :pageType)')
            ->andWhere('entry.publishedAt IS NOT NULL')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = :unlisted')
            ->andWhere('(entry.scheduledUnpublishAt IS NULL OR entry.scheduledUnpublishAt > :now)')
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('newsType', ContentEntry::TYPE_NEWS)
            ->setParameter('pageType', ContentEntry::TYPE_PAGE)
            ->setParameter('now', $now)
            ->setParameter('unlisted', false);
    }
}
