<?php

declare(strict_types=1);

namespace App\Repository\GameCatalogue;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GameRelease> */
final class GameReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameRelease::class);
    }

    /** @return list<GameRelease> */
    public function upcoming(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('release')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.releaseAt <= :to')
            ->andWhere('release.status != :cancelled')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('release.releaseAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<GameRelease> */
    public function forEntry(GameCatalogueEntry $entry): array
    {
        return $this->findBy(['entry' => $entry], ['releaseAt' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<GamePlatform> */
    public function calendarPlatforms(\DateTimeImmutable $from): array
    {
        $platforms = $this->createQueryBuilder('release')
            ->select('DISTINCT platform')
            ->join('release.platform', 'platform')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.status != :cancelled')
            ->setParameter('from', $from)
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('platform.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($platforms, static fn (mixed $platform): bool => $platform instanceof GamePlatform));
    }

    /** @return list<string> */
    public function calendarRegions(\DateTimeImmutable $from): array
    {
        $rows = $this->createQueryBuilder('release')
            ->select('DISTINCT release.region AS region')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.status != :cancelled')
            ->setParameter('from', $from)
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('release.region', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $regions = [];
        foreach ($rows as $row) {
            $region = $row['region'] ?? null;
            if (is_string($region) && trim($region) !== '') {
                $regions[] = $region;
            }
        }

        return $regions;
    }

    public function countCalendarResults(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $search,
        ?int $platformId,
        string $region,
        string $status,
    ): int {
        return (int) $this->calendarQuery($from, $to, $search, $platformId, $region, $status)
            ->select('COUNT(release.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<GameRelease> */
    public function calendarPage(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $search,
        ?int $platformId,
        string $region,
        string $status,
        int $offset,
        int $limit,
    ): array {
        return $this->calendarQuery($from, $to, $search, $platformId, $region, $status)
            ->select('release')
            ->addSelect('entry', 'game', 'platform', 'edition')
            ->leftJoin('release.edition', 'edition')
            ->orderBy('release.releaseAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function calendarQuery(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $search,
        ?int $platformId,
        string $region,
        string $status,
    ): QueryBuilder {
        $query = $this->createQueryBuilder('release')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->join('release.platform', 'platform')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.releaseAt < :to')
            ->andWhere('release.status != :cancelled')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', 'cancelled');

        if ($search !== '') {
            $query->andWhere('LOWER(game.name) LIKE :gameName')
                ->setParameter('gameName', '%'.mb_strtolower($search).'%');
        }

        if ($platformId !== null) {
            $query->andWhere('platform.id = :platformId')->setParameter('platformId', $platformId);
        }

        if ($region !== '') {
            $query->andWhere('release.region = :region')->setParameter('region', $region);
        }

        if ($status !== '') {
            $query->andWhere('release.status = :status')->setParameter('status', $status);
        }

        return $query;
    }
}
