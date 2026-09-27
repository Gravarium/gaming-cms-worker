<?php

declare(strict_types=1);

namespace App\Widget\Content;

use App\Entity\Category;
use App\Entity\ContentEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;

final readonly class PublicContentCategoryQuery
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_ITEMS = 12;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<Category> */
    public function findPublic(int $limit = self::DEFAULT_LIMIT): array
    {
        $now = new \DateTimeImmutable();

        /** @var list<Category> $categories */
        $categories = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT category')
            ->addSelect('parent')
            ->from(Category::class, 'category')
            ->innerJoin(ContentEntry::class, 'entry', Join::WITH, 'entry.category = category')
            ->leftJoin('category.parent', 'parent')
            ->andWhere('entry.type = :type')
            ->andWhere('entry.status = :status')
            ->andWhere('entry.publishedAt IS NOT NULL')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = :unlisted')
            ->andWhere('(entry.scheduledUnpublishAt IS NULL OR entry.scheduledUnpublishAt > :now)')
            ->setParameter('type', ContentEntry::TYPE_NEWS)
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', $now)
            ->setParameter('unlisted', false)
            ->orderBy('category.name', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->setMaxResults(max(1, min(self::MAX_ITEMS, $limit)))
            ->getQuery()
            ->getResult();

        return $categories;
    }
}
