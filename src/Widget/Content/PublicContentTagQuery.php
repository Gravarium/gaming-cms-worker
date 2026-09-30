<?php

declare(strict_types=1);

namespace App\Widget\Content;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicContentTagQuery
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_ITEMS = 12;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<ContentTag>
     */
    public function findPublic(int $limit = self::DEFAULT_LIMIT): array
    {
        $now = new \DateTimeImmutable();

        /** @var list<ContentTag> $tags */
        $tags = $this->entityManager->createQueryBuilder()
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
            ->setParameter('unlisted', false)
            ->orderBy('tag.name', 'ASC')
            ->addOrderBy('tag.id', 'ASC')
            ->setMaxResults(max(1, min(self::MAX_ITEMS, $limit)))
            ->getQuery()
            ->getResult();

        return $tags;
    }
}
