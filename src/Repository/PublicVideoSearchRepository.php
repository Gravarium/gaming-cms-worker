<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicVideoSearchRepository
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countMatches(string $query, \DateTimeImmutable $now): int
    {
        if (trim($query) === '') {
            return 0;
        }

        return (int) $this->matchingVideos($query, $now)
            ->select('COUNT(video.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Video> */
    public function findMatches(string $query, \DateTimeImmutable $now, int $page): array
    {
        if (trim($query) === '') {
            return [];
        }

        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page must be within the supported public search range.');
        }

        /** @var list<Video> $videos */
        $videos = $this->matchingVideos($query, $now)
            ->select('video')
            ->orderBy('video.publishedAt', 'DESC')
            ->addOrderBy('video.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $videos;
    }

    private function matchingVideos(string $query, \DateTimeImmutable $now): QueryBuilder
    {
        $needle = mb_strtolower($query, 'UTF-8');

        return $this->entityManager->createQueryBuilder()
            ->from(Video::class, 'video')
            ->andWhere('video.enabled = :enabled')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->andWhere('(LOCATE(:needle, LOWER(video.title)) > 0 OR LOCATE(:needle, LOWER(video.description)) > 0)')
            ->setParameter('enabled', true)
            ->setParameter('now', $now)
            ->setParameter('needle', $needle);
    }
}
