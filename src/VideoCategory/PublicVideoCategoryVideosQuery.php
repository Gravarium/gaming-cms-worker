<?php

declare(strict_types=1);

namespace App\VideoCategory;

use App\Entity\Video;
use App\Entity\VideoCategory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Provides bounded, public video results for one enabled category.
 */
final class PublicVideoCategoryVideosQuery
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function countPublishedInCategory(VideoCategory $category, \DateTimeImmutable $now): int
    {
        return (int) $this->publishedInCategoryBuilder($category, $now)
            ->select('COUNT(video.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Video>
     */
    public function pagePublishedInCategory(VideoCategory $category, \DateTimeImmutable $now, int $page): array
    {
        $this->assertPage($page);

        $idRows = $this->publishedInCategoryBuilder($category, $now)
            ->select('video.id AS id')
            ->orderBy('video.featured', 'DESC')
            ->addOrderBy('video.publishedAt', 'DESC')
            ->addOrderBy('video.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getScalarResult();

        /** @var list<array{id: int|string}> $idRows */
        $videoIds = array_map(static fn (array $row): int => (int) $row['id'], $idRows);
        if ($videoIds === []) {
            return [];
        }

        /** @var list<Video> $videos */
        $videos = $this->publishedInCategoryBuilder($category, $now)
            ->addSelect('category')
            ->andWhere('video.id IN (:videoIds)')
            ->setParameter('videoIds', $videoIds)
            ->orderBy('video.featured', 'DESC')
            ->addOrderBy('video.publishedAt', 'DESC')
            ->addOrderBy('video.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $videos;
    }

    private function assertPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page must be within the supported video category range.');
        }
    }

    private function publishedInCategoryBuilder(VideoCategory $category, \DateTimeImmutable $now): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('video')
            ->from(Video::class, 'video')
            ->join('video.category', 'category')
            ->andWhere('category = :category')
            ->andWhere('category.enabled = true')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('category', $category)
            ->setParameter('now', $now);
    }
}
