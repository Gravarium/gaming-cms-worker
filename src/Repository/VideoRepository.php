<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Video> */
final class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Video::class); }

    /** @return list<Video> */
    public function findPublished(?VideoCategory $category = null, ?VideoPlaylist $playlist = null): array
    {
        $builder = $this->createQueryBuilder('video')
            ->addSelect('category', 'playlists', 'media')
            ->leftJoin('video.category', 'category')
            ->leftJoin('video.playlists', 'playlists')
            ->leftJoin('video.mediaAsset', 'media')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('video.featured', 'DESC')
            ->addOrderBy('video.publishedAt', 'DESC');
        if ($category !== null) { $builder->andWhere('video.category = :category')->setParameter('category', $category); }
        if ($playlist !== null) { $builder->andWhere(':playlist MEMBER OF video.playlists')->setParameter('playlist', $playlist); }

        return $builder->getQuery()->getResult();
    }

    /** @return list<Video> */
    public function searchPublished(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if (!mb_check_encoding($query, 'UTF-8')) {
            return [];
        }

        $length = mb_strlen($query, 'UTF-8');
        if ($length < 2 || $length > 100) {
            return [];
        }

        return $this->createQueryBuilder('video')
            ->addSelect('category')
            ->leftJoin('video.category', 'category')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('(LOWER(video.title) LIKE :query OR LOWER(video.description) LIKE :query)')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('query', '%'.mb_strtolower($query, 'UTF-8').'%')
            ->orderBy('video.featured', 'DESC')
            ->addOrderBy('video.publishedAt', 'DESC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function findPublishedBySlug(string $slug): ?Video
    {
        return $this->createQueryBuilder('video')
            ->addSelect('category', 'playlists', 'media')
            ->leftJoin('video.category', 'category')
            ->leftJoin('video.playlists', 'playlists')
            ->leftJoin('video.mediaAsset', 'media')
            ->andWhere('video.slug = :slug')->setParameter('slug', $slug)
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getOneOrNullResult();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('video')->select('COUNT(video.id)')
            ->andWhere('video.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) { $builder->andWhere('video.id != :id')->setParameter('id', $exceptId); }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}
