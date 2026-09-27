<?php

declare(strict_types=1);

namespace App\Video\Library;

use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class VideoLibraryBrowser
{
    public const PAGE_SIZE = 24;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{videos: list<Video>, total: int}
     */
    public function page(?VideoCategory $category, ?VideoPlaylist $playlist, int $page): array
    {
        $now = new \DateTimeImmutable();
        $total = (int) $this->publishedQuery($category, $playlist, $now)
            ->select('COUNT(DISTINCT video.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $videos = $this->publishedQuery($category, $playlist, $now)
            ->leftJoin('video.category', 'category')
            ->select('video', 'category')
            ->orderBy('video.featured', 'DESC')
            ->addOrderBy('video.publishedAt', 'DESC')
            ->addOrderBy('video.id', 'DESC')
            ->setFirstResult((max(1, $page) - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        /** @var list<Video> $videos */
        return ['videos' => $videos, 'total' => $total];
    }

    public function countPublic(): int
    {
        return (int) $this->publishedQuery(null, null, new \DateTimeImmutable())
            ->select('COUNT(DISTINCT video.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function publishedQuery(
        ?VideoCategory $category,
        ?VideoPlaylist $playlist,
        \DateTimeImmutable $now,
    ): QueryBuilder {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(Video::class, 'video')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', $now);

        if ($category !== null) {
            $builder->andWhere('video.category = :category')->setParameter('category', $category);
        }
        if ($playlist !== null) {
            $builder->andWhere(':playlist MEMBER OF video.playlists')->setParameter('playlist', $playlist);
        }

        return $builder;
    }
}
