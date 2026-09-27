<?php

declare(strict_types=1);

namespace App\Widget\GameRelease;

use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpcomingReleaseQuery
{
    private const MAX_RESULTS = 12;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<GameRelease>
     */
    public function findUpcoming(\DateTimeImmutable $from, int $limit = self::MAX_RESULTS): array
    {
        $boundedLimit = max(1, min(self::MAX_RESULTS, $limit));

        /** @var list<GameRelease> $releases */
        $releases = $this->entityManager->createQueryBuilder()
            ->select('release', 'entry', 'game', 'edition', 'platform')
            ->from(GameRelease::class, 'release')
            ->innerJoin('release.entry', 'entry')
            ->innerJoin('entry.game', 'game')
            ->leftJoin('release.edition', 'edition')
            ->innerJoin('release.platform', 'platform')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.status <> :cancelled')
            ->setParameter('from', $from)
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('release.releaseAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setMaxResults($boundedLimit)
            ->getQuery()
            ->getResult();

        return $releases;
    }
}
