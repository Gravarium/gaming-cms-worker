<?php

declare(strict_types=1);

namespace App\Widget\VideoPlaylist;

use App\Entity\VideoPlaylist;
use App\Repository\VideoPlaylistRepository;

final readonly class PublicVideoPlaylistQuery
{
    private const MAX_RESULTS = 12;

    public function __construct(private VideoPlaylistRepository $playlists)
    {
    }

    /** @return list<VideoPlaylist> */
    public function find(int $limit = self::MAX_RESULTS): array
    {
        $limit = max(1, min(self::MAX_RESULTS, $limit));

        return $this->playlists->createQueryBuilder('playlist')
            ->select('DISTINCT playlist')
            ->innerJoin('playlist.videos', 'video')
            ->andWhere('playlist.enabled = true')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('playlist.title', 'ASC')
            ->addOrderBy('playlist.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
