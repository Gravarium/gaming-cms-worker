<?php

declare(strict_types=1);

namespace App\Repository\GameCatalogue;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePlatform;
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
     * @return list<GameGenre>
     */
    public function publicGenres(): array
    {
        return $this->createQueryBuilder('entry')
            ->select('DISTINCT genre')
            ->join('entry.game', 'game')
            ->join('entry.genres', 'genre')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->orderBy('genre.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<GamePlatform>
     */
    public function publicPlatforms(): array
    {
        return $this->createQueryBuilder('entry')
            ->select('DISTINCT platform')
            ->join('entry.game', 'game')
            ->innerJoin(GameRelease::class, 'release', Join::WITH, 'release.entry = entry')
            ->join('release.platform', 'platform')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.status != :cancelled')
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('platform.name', 'ASC')
            ->getQuery()
            ->getResult();
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
