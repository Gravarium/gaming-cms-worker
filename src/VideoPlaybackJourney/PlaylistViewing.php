<?php

declare(strict_types=1);

namespace App\VideoPlaybackJourney;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoPlaylist;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlaylistViewing
{
    public function __construct(private EntityManagerInterface $em, private ViewingSources $sources) {}

    /** Stable ordering: the legacy playlist has no per-item order field. */
    public function adjacent(VideoPlaylist $playlist, int $cursor, ?User $viewer, bool $previous = false): ?Video
    {
        do {
            $rows = $this->em->getRepository(Video::class)->createQueryBuilder('v')->innerJoin('v.playlists', 'p')
                ->where('p = :playlist AND v.enabled = :enabled AND v.publishedAt <= :now')
                ->andWhere($previous ? 'v.id < :cursor' : 'v.id > :cursor')
                ->setParameter('playlist', $playlist)->setParameter('enabled', true)->setParameter('now', new \DateTimeImmutable())
                ->setParameter('cursor', $cursor)->orderBy('v.id', $previous ? 'DESC' : 'ASC')->setMaxResults(50)->getQuery()->getResult();
            foreach ($rows as $row) {
                if (!$row instanceof Video) { continue; }
                $cursor = $row->getId() ?? $cursor;
                if ($this->sources->visible($row, $viewer)) { return $row; }
            }
        } while (count($rows) === 50);
        return null;
    }

    public function contains(VideoPlaylist $playlist, Video $video): bool
    {
        return (int) $this->em->getRepository(Video::class)->createQueryBuilder('v')->select('COUNT(v.id)')
            ->innerJoin('v.playlists', 'p')->where('p = :p AND v = :v')->setParameter('p', $playlist)->setParameter('v', $video)
            ->getQuery()->getSingleScalarResult() === 1;
    }
}
