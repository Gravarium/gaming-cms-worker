<?php

declare(strict_types=1);

namespace App\Repository\GameCatalogue;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GameCatalogueEntry> */
final class GameCatalogueEntryRepository extends ServiceEntityRepository
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameCatalogueEntry::class);
    }

    public function countPublicEntries(?string $genreSlug = null, ?string $platformSlug = null): int
    {
        return (int) $this->publicEntriesBuilder($genreSlug, $platformSlug)
            ->select('COUNT(DISTINCT entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<GameCatalogueEntry>
     */
    public function publicEntries(?string $genreSlug = null, ?string $platformSlug = null, int $page = 1): array
    {
        $this->assertPage($page);

        $idRows = $this->publicEntriesBuilder($genreSlug, $platformSlug)
            ->select('DISTINCT entry.id AS id, game.name AS gameName')
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getScalarResult();

        /** @var list<array{id: int|string, gameName: string}> $idRows */
        $entryIds = array_map(static fn (array $row): int => (int) $row['id'], $idRows);
        if ($entryIds === []) {
            return [];
        }

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->publicEntriesBuilder($genreSlug, $platformSlug)
            ->addSelect('game', 'genre')
            ->andWhere('entry.id IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->distinct()
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public function publicGenres(): array
    {
        $rows = $this->createQueryBuilder('entry')
            ->select('DISTINCT genre.slug AS slug')
            ->addSelect('genre.name AS name')
            ->join('entry.game', 'game')
            ->join('entry.genres', 'genre')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->orderBy('genre.name', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $genres = [];
        foreach ($rows as $row) {
            $genres[] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
            ];
        }

        return $genres;
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public function publicPlatforms(): array
    {
        $rows = $this->createQueryBuilder('entry')
            ->select('DISTINCT platform.slug AS slug')
            ->addSelect('platform.name AS name')
            ->join('entry.game', 'game')
            ->innerJoin(GameRelease::class, 'release', Join::WITH, 'release.entry = entry')
            ->join('release.platform', 'platform')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.status != :cancelled')
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('platform.name', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $platforms = [];
        foreach ($rows as $row) {
            $platforms[] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
            ];
        }

        return $platforms;
    }

    public function publicBySlug(string $slug): ?GameCatalogueEntry
    {
        return $this->createQueryBuilder('entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('game.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function assertPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page must be within the supported Game Catalogue range.');
        }
    }

    private function publicEntriesBuilder(?string $genreSlug, ?string $platformSlug): QueryBuilder
    {
        $builder = $this->createQueryBuilder('entry')
            ->join('entry.game', 'game')
            ->leftJoin('entry.genres', 'genre')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true');

        if ($genreSlug !== null) {
            $builder
                ->andWhere('genre.slug = :genreSlug')
                ->setParameter('genreSlug', $genreSlug);
        }

        if ($platformSlug !== null) {
            $builder
                ->innerJoin(GameRelease::class, 'release', Join::WITH, 'release.entry = entry')
                ->innerJoin('release.platform', 'platform')
                ->andWhere('release.status != :cancelled')
                ->andWhere('platform.slug = :platformSlug')
                ->setParameter('cancelled', 'cancelled')
                ->setParameter('platformSlug', $platformSlug);
        }

        return $builder;
    }
}
