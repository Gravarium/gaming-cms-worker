<?php

declare(strict_types=1);

namespace App\VideoSitemap;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicVideoSitemapQuery
{
    public const MAX_URLS = 50000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<Video>
     */
    public function findPublishedVideos(int $limit = self::MAX_URLS): array
    {
        $now = new \DateTimeImmutable();
        $result = $this->entityManager->createQueryBuilder()
            ->select('video')
            ->from(Video::class, 'video')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('video.publishedAt', 'DESC')
            ->addOrderBy('video.id', 'DESC')
            ->setMaxResults(self::boundedLimit($limit))
            ->getQuery()
            ->getResult();

        /** @var list<Video> $result */
        return $result;
    }

    public static function boundedLimit(int $requested): int
    {
        return max(1, min(self::MAX_URLS, $requested));
    }
}
