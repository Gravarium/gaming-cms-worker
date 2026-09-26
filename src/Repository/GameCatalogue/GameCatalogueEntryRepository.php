<?php

declare(strict_types=1);

namespace App\Repository\GameCatalogue;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GameCatalogueEntry> */
final class GameCatalogueEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameCatalogueEntry::class);
    }

    /**
     * @return list<GameCatalogueEntry>
     */
    public function publicEntries(?string $genreSlug = null, ?string $platformSlug = null): array
    {
        $builder = $this->createQueryBuilder('entry')
            ->addSelect('game')
            ->addSelect('genre')
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

        return $builder
            ->distinct()
            ->orderBy('game.name', 'ASC')
            ->getQuery()
            ->getResult();
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
}
