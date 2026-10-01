<?php

declare(strict_types=1);

namespace App\GameReleaseArchive;

use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicGameReleaseArchiveQuery
{
    public const PAGE_SIZE = 20;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function count(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->visibleQuery($from, $to)
            ->select('COUNT(release.id)')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<GameRelease> */
    public function page(\DateTimeImmutable $from, \DateTimeImmutable $to, int $page): array
    {
        return $this->visibleQuery($from, $to)
            ->select('release', 'entry', 'game', 'platform', 'edition')
            ->leftJoin('release.edition', 'edition')
            ->orderBy('release.releaseAt', 'DESC')
            ->addOrderBy('release.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()->getResult();
    }

    private function visibleQuery(\DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(GameRelease::class, 'release')
            ->innerJoin('release.entry', 'entry')
            ->innerJoin('entry.game', 'game')
            ->innerJoin('release.platform', 'platform')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.status = :released')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.releaseAt < :to')
            ->setParameter('released', 'released')
            ->setParameter('from', $from)
            ->setParameter('to', $to);
    }
}
