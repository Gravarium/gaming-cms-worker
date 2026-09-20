<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentEntry> */
final class ContentEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ContentEntry::class); }

    /** @return list<ContentEntry> */
    public function findPublishedNews(int $limit = 50, int $offset = 0, ?Category $category = null, ?ContentTag $tag = null): array
    {
        $builder = $this->listedBuilder()->andWhere('entry.type = :type')->setParameter('type', ContentEntry::TYPE_NEWS)
            ->orderBy('entry.pinned', 'DESC')->addOrderBy('entry.publishedAt', 'DESC')
            ->setFirstResult(max(0, $offset))->setMaxResults(max(1, min(100, $limit)));
        if ($category !== null) { $builder->andWhere('entry.category = :category')->setParameter('category', $category); }
        if ($tag !== null) { $builder->join('entry.tags', 'filterTag')->andWhere('filterTag = :tag')->setParameter('tag', $tag); }
        return $builder->getQuery()->getResult();
    }

    public function countPublishedNews(?Category $category = null, ?ContentTag $tag = null): int
    {
        $builder = $this->listedBuilder()->select('COUNT(DISTINCT entry.id)')->andWhere('entry.type = :type')->setParameter('type', ContentEntry::TYPE_NEWS);
        if ($category !== null) { $builder->andWhere('entry.category = :category')->setParameter('category', $category); }
        if ($tag !== null) { $builder->join('entry.tags', 'countTag')->andWhere('countTag = :tag')->setParameter('tag', $tag); }
        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /** @return list<ContentEntry> */
    public function findPublishedAll(int $limit = 1000): array
    {
        return $this->listedBuilder()->orderBy('entry.publishedAt', 'DESC')->setMaxResults(max(1, min(5000, $limit)))->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findFeaturedNews(int $limit = 6): array
    {
        return $this->listedBuilder()->andWhere('entry.type = :type')->setParameter('type', ContentEntry::TYPE_NEWS)
            ->andWhere('entry.featured = true')->orderBy('entry.pinned', 'DESC')->addOrderBy('entry.publishedAt', 'DESC')
            ->setMaxResults(max(1, min(20, $limit)))->getQuery()->getResult();
    }

    public function findPublishedBySlug(string $slug, ?string $type = null): ?ContentEntry
    {
        $builder = $this->publishedBuilder()->andWhere('entry.slug = :slug')->setParameter('slug', $slug);
        if ($type !== null) { $builder->andWhere('entry.type = :type')->setParameter('type', $type); }
        return $builder->getQuery()->getOneOrNullResult();
    }

    /** @return list<ContentEntry> */
    public function searchPublished(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) { return []; }
        return $this->listedBuilder()->leftJoin('entry.tags', 'searchTag')->distinct()
            ->andWhere('LOWER(entry.title) LIKE :query OR LOWER(entry.subtitle) LIKE :query OR LOWER(entry.excerpt) LIKE :query OR LOWER(entry.body) LIKE :query OR LOWER(searchTag.name) LIKE :query')
            ->setParameter('query', '%'.mb_strtolower($query).'%')->orderBy('entry.pinned', 'DESC')->addOrderBy('entry.publishedAt', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function searchAdmin(?string $query, ?string $status, ?string $type, ?Category $category = null, ?ContentTag $tag = null, int $limit = 200): array
    {
        $builder = $this->createQueryBuilder('entry')->distinct()->orderBy('entry.updatedAt', 'DESC')->setMaxResults(max(1, min(500, $limit)));
        $query = trim((string) $query);
        if ($query !== '') {
            $builder->leftJoin('entry.tags', 'adminTag')
                ->andWhere('LOWER(entry.title) LIKE :query OR LOWER(entry.subtitle) LIKE :query OR LOWER(entry.slug) LIKE :query OR LOWER(entry.excerpt) LIKE :query OR LOWER(adminTag.name) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (in_array($status, [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW, ContentEntry::STATUS_SCHEDULED, ContentEntry::STATUS_PUBLISHED, ContentEntry::STATUS_ARCHIVED, ContentEntry::STATUS_TRASHED], true)) { $builder->andWhere('entry.status = :status')->setParameter('status', $status); }
        if (in_array($type, [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)) { $builder->andWhere('entry.type = :type')->setParameter('type', $type); }
        if ($category !== null) { $builder->andWhere('entry.category = :category')->setParameter('category', $category); }
        if ($tag !== null) { $builder->join('entry.tags', 'adminFilterTag')->andWhere('adminFilterTag = :filterTag')->setParameter('filterTag', $tag); }
        return $builder->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findDueForPublication(\DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('entry')->andWhere('entry.status = :status')->andWhere('entry.scheduledAt <= :now')
            ->setParameter('status', ContentEntry::STATUS_SCHEDULED)->setParameter('now', $now)
            ->orderBy('entry.scheduledAt', 'ASC')->setMaxResults(max(1, min(500, $limit)))->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findDueForUnpublication(\DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('entry')->andWhere('entry.status = :status')->andWhere('entry.scheduledUnpublishAt IS NOT NULL')->andWhere('entry.scheduledUnpublishAt <= :now')
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)->setParameter('now', $now)
            ->orderBy('entry.scheduledUnpublishAt', 'ASC')->setMaxResults(max(1, min(500, $limit)))->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findRelated(ContentEntry $current, int $limit = 4): array
    {
        $builder = $this->listedBuilder()->andWhere('entry.id != :id')->setParameter('id', $current->getId())
            ->andWhere('entry.type = :type')->setParameter('type', $current->getType())->setMaxResults(max(1, min(12, $limit)));
        $conditions = [];
        if ($current->getCategory() !== null) { $conditions[] = 'entry.category = :relatedCategory'; $builder->setParameter('relatedCategory', $current->getCategory()); }
        $tagIds = array_values(array_filter(array_map(static fn (ContentTag $tag): ?int => $tag->getId(), $current->getTags()->toArray())));
        if ($tagIds !== []) { $builder->leftJoin('entry.tags', 'relatedTag'); $conditions[] = 'relatedTag.id IN (:relatedTagIds)'; $builder->setParameter('relatedTagIds', $tagIds); }
        if ($conditions === []) { return []; }
        return $builder->andWhere('('.implode(' OR ', $conditions).')')->orderBy('entry.featured', 'DESC')->addOrderBy('entry.publishedAt', 'DESC')->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findUsingMediaLocation(string $location): array
    {
        return $this->createQueryBuilder('entry')->andWhere('entry.body LIKE :location OR entry.excerpt LIKE :location')->setParameter('location', '%'.addcslashes($location, '%_').'%')->getQuery()->getResult();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('entry')->select('COUNT(entry.id)')->andWhere('entry.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) { $builder->andWhere('entry.id != :id')->setParameter('id', $exceptId); }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }

    private function listedBuilder(): QueryBuilder { return $this->publishedBuilder()->andWhere('entry.unlisted = false'); }
    private function publishedBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('entry')->andWhere('entry.status = :status')->andWhere('entry.publishedAt <= :now')
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)->setParameter('now', new \DateTimeImmutable());
    }
}
