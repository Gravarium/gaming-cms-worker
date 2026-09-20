<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentEntry> */
final class ContentEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentEntry::class);
    }

    /** @return list<ContentEntry> */
    public function findPublishedNews(): array
    {
        return $this->publishedBuilder()
            ->andWhere('entry.type = :type')->setParameter('type', ContentEntry::TYPE_NEWS)
            ->orderBy('entry.publishedAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function findPublishedBySlug(string $slug, ?string $type = null): ?ContentEntry
    {
        $builder = $this->publishedBuilder()->andWhere('entry.slug = :slug')->setParameter('slug', $slug);
        if ($type !== null) {
            $builder->andWhere('entry.type = :type')->setParameter('type', $type);
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    /** @return list<ContentEntry> */
    public function searchPublished(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->publishedBuilder()
            ->andWhere('LOWER(entry.title) LIKE :query OR LOWER(entry.excerpt) LIKE :query OR LOWER(entry.body) LIKE :query')
            ->setParameter('query', '%'.mb_strtolower($query).'%')
            ->orderBy('entry.publishedAt', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function searchAdmin(?string $query, ?string $status, ?string $type): array
    {
        $builder = $this->createQueryBuilder('entry')->orderBy('entry.updatedAt', 'DESC')->setMaxResults(200);
        $query = trim((string) $query);
        if ($query !== '') {
            $builder->andWhere('LOWER(entry.title) LIKE :query OR LOWER(entry.slug) LIKE :query OR LOWER(entry.excerpt) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (in_array($status, [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW, ContentEntry::STATUS_SCHEDULED, ContentEntry::STATUS_PUBLISHED, ContentEntry::STATUS_ARCHIVED], true)) {
            $builder->andWhere('entry.status = :status')->setParameter('status', $status);
        }
        if (in_array($type, [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)) {
            $builder->andWhere('entry.type = :type')->setParameter('type', $type);
        }

        return $builder->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findDueForPublication(\DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.status = :status')->andWhere('entry.scheduledAt <= :now')
            ->setParameter('status', ContentEntry::STATUS_SCHEDULED)->setParameter('now', $now)
            ->orderBy('entry.scheduledAt', 'ASC')->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()->getResult();
    }

    /** @return list<ContentEntry> */
    public function findUsingMediaLocation(string $location): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.body LIKE :location OR entry.excerpt LIKE :location')
            ->setParameter('location', '%'.addcslashes($location, '%_').'%')
            ->getQuery()->getResult();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('entry')->select('COUNT(entry.id)')
            ->andWhere('entry.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) {
            $builder->andWhere('entry.id != :id')->setParameter('id', $exceptId);
        }

        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }

    private function publishedBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.status = :status')
            ->andWhere('entry.publishedAt <= :now')
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', new \DateTimeImmutable());
    }
}
