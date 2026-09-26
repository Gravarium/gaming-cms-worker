<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentEntry> */
final class AdminContentBrowserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentEntry::class);
    }

    /**
     * @return array{entries: list<ContentEntry>, total: int, page: int, perPage: int, pageCount: int}
     */
    public function searchAdminPage(
        ?string $query,
        ?string $status,
        ?string $type,
        ?Category $category = null,
        ?ContentTag $tag = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $builder = $this->createQueryBuilder('entry')->distinct();
        $query = trim((string) $query);
        if ($query !== '') {
            $builder->leftJoin('entry.tags', 'adminTag')
                ->andWhere('LOWER(entry.title) LIKE :query OR LOWER(entry.subtitle) LIKE :query OR LOWER(entry.slug) LIKE :query OR LOWER(entry.excerpt) LIKE :query OR LOWER(adminTag.name) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (in_array($status, [
            ContentEntry::STATUS_DRAFT,
            ContentEntry::STATUS_REVIEW,
            ContentEntry::STATUS_SCHEDULED,
            ContentEntry::STATUS_PUBLISHED,
            ContentEntry::STATUS_ARCHIVED,
            ContentEntry::STATUS_TRASHED,
        ], true)) {
            $builder->andWhere('entry.status = :status')->setParameter('status', $status);
        }
        if (in_array($type, [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)) {
            $builder->andWhere('entry.type = :type')->setParameter('type', $type);
        }
        if ($category !== null) {
            $builder->andWhere('entry.category = :category')->setParameter('category', $category);
        }
        if ($tag !== null) {
            $builder->join('entry.tags', 'adminFilterTag')
                ->andWhere('adminFilterTag = :filterTag')->setParameter('filterTag', $tag);
        }

        $countBuilder = clone $builder;
        $total = (int) $countBuilder
            ->resetDQLPart('select')
            ->select('COUNT(DISTINCT entry.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);

        /** @var list<ContentEntry> $entries */
        $entries = $builder
            ->orderBy('entry.updatedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => $pageCount,
        ];
    }
}
