<?php

declare(strict_types=1);

namespace App\GameReleaseFeed;

use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGameReleaseFeedQuery
{
    public const MAX_ITEMS = 200;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<GameRelease> */
    public function findUpcoming(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = self::MAX_ITEMS): array
    {
        /** @var list<GameRelease> $releases */
        $releases = $this->entityManager->createQueryBuilder()
            ->select('release', 'entry', 'game', 'edition', 'platform')
            ->from(GameRelease::class, 'release')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->leftJoin('release.edition', 'edition')
            ->join('release.platform', 'platform')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.releaseAt <= :to')
            ->andWhere('release.status IN (:statuses)')
            ->setParameter('enabled', true)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statuses', ['announced', 'released', 'delayed'])
            ->orderBy('release.releaseAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setMaxResults(max(1, min(self::MAX_ITEMS, $limit)))
            ->getQuery()
            ->getResult();

        return $releases;
    }
}
