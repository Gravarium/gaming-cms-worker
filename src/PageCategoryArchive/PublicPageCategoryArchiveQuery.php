<?php

declare(strict_types=1);

namespace App\PageCategoryArchive;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicPageCategoryArchiveQuery
{
    public const MAX_DIRECTORY_CATEGORIES = 100;

    public function __construct(
        private ContentEntryRepository $entries,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * @return list<array{category: Category, pageCount: int}>
     */
    public function findCategoriesWithPublicPages(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_DIRECTORY_CATEGORIES, $limit));
        $offset = max(0, $offset);

        $rows = $this->publicPages()
            ->select('category.id AS categoryId')
            ->addSelect('category.name AS categoryName')
            ->addSelect('COUNT(entry.id) AS pageCount')
            ->join('entry.category', 'category')
            ->groupBy('category.id')
            ->addGroupBy('category.name')
            ->orderBy('category.name', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        /** @var list<array{categoryId: int|string, categoryName: string, pageCount: int|string}> $rows */
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['categoryId'], $rows);
        /** @var list<Category> $categories */
        $categories = $this->categories->findBy(['id' => $ids]);
        $byId = [];
        foreach ($categories as $category) {
            $id = $category->getId();
            if ($id !== null) {
                $byId[$id] = $category;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['categoryId'];
            if (isset($byId[$id])) {
                $result[] = [
                    'category' => $byId[$id],
                    'pageCount' => (int) $row['pageCount'],
                ];
            }
        }

        return $result;
    }

    public function countCategoriesWithPublicPages(): int
    {
        return (int) $this->publicPages()
            ->select('COUNT(DISTINCT category.id)')
            ->join('entry.category', 'category')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPublicPages(Category $category): int
    {
        return (int) $this->publicPages()
            ->select('COUNT(entry.id)')
            ->andWhere('entry.category = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ContentEntry>
     */
    public function findPublicPages(Category $category, int $limit = 20, int $offset = 0): array
    {
        /** @var list<ContentEntry> $entries */
        $entries = $this->publicPages()
            ->andWhere('entry.category = :category')
            ->setParameter('category', $category)
            ->orderBy('entry.pinned', 'DESC')
            ->addOrderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(self::MAX_DIRECTORY_CATEGORIES, $limit)))
            ->getQuery()
            ->getResult();

        return $entries;
    }

    private function publicPages(): QueryBuilder
    {
        $now = new \DateTimeImmutable();

        return $this->entries->createQueryBuilder('entry')
            ->andWhere('entry.type = :type')
            ->andWhere('entry.status = :status')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = false')
            ->andWhere('(entry.scheduledUnpublishAt IS NULL OR entry.scheduledUnpublishAt > :now)')
            ->setParameter('type', ContentEntry::TYPE_PAGE)
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', $now);
    }
}
