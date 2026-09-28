<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameCatalogue\GameRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GameRelease> */
final class GameReleaseCalendarFeedRepository extends ServiceEntityRepository
{
    public const MAX_RESULTS = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameRelease::class);
    }

    /**
     * @return list<GameRelease>
     */
    public function upcoming(\DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('release')
            ->addSelect('entry', 'game', 'platform', 'edition')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->join('release.platform', 'platform')
            ->leftJoin('release.edition', 'edition')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.releaseAt < :until')
            ->andWhere('release.status IN (:statuses)')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('statuses', ['announced', 'delayed'])
            ->orderBy('release.releaseAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setMaxResults(self::MAX_RESULTS)
            ->getQuery()
            ->getResult();
    }
}
