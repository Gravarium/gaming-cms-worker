<?php

declare(strict_types=1);

namespace App\GameGenreDirectory;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicGameGenreDirectoryQuery
{
    public const GENRE_PAGE_SIZE = 40;
    public const GAME_PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;
    public const MAX_WIDGET_ITEMS = 12;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countPublicGenres(): int
    {
        return (int) $this->publicGenres()
            ->select('COUNT(genre.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<GameGenre> */
    public function findPublicGenres(int $page = 1, int $limit = self::GENRE_PAGE_SIZE): array
    {
        $this->assertValidPage($page);
        $limit = max(1, min(self::GENRE_PAGE_SIZE, $limit));

        /** @var list<GameGenre> $genres */
        $genres = $this->publicGenres()
            ->select('genre')
            ->orderBy('LOWER(genre.name)', 'ASC')
            ->addOrderBy('genre.id', 'ASC')
            ->setFirstResult(($page - 1) * self::GENRE_PAGE_SIZE)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $genres;
    }

    public function findPublicGenreBySlug(string $slug): ?GameGenre
    {
        $genre = $this->publicGenres()
            ->select('genre')
            ->andWhere('genre.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $genre instanceof GameGenre ? $genre : null;
    }

    public function countPublicGamesByGenre(string $slug): int
    {
        return (int) $this->publicEntriesForGenre($slug)
            ->select('COUNT(DISTINCT entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<GameCatalogueEntry> */
    public function findPublicGamesByGenre(string $slug, int $page = 1): array
    {
        $this->assertValidPage($page);

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->publicEntriesForGenre($slug)
            ->select('DISTINCT entry', 'game')
            ->orderBy('LOWER(game.name)', 'ASC')
            ->addOrderBy('game.id', 'ASC')
            ->setFirstResult(($page - 1) * self::GAME_PAGE_SIZE)
            ->setMaxResults(self::GAME_PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    private function publicGenres(): QueryBuilder
    {
        $subquery = $this->entityManager->createQueryBuilder()
            ->select('entryCheck.id')
            ->from(GameCatalogueEntry::class, 'entryCheck')
            ->join('entryCheck.game', 'gameCheck')
            ->join('entryCheck.genres', 'genreCheck')
            ->where('genreCheck.id = genre.id')
            ->andWhere('entryCheck.enabled = :enabled')
            ->andWhere('gameCheck.enabled = :enabled');

        $query = $this->entityManager->createQueryBuilder()
            ->from(GameGenre::class, 'genre');

        return $query
            ->andWhere($query->expr()->exists($subquery->getDQL()))
            ->setParameter('enabled', true);
    }

    private function publicEntriesForGenre(string $slug): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->join('entry.genres', 'genre')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->andWhere('genre.slug = :slug')
            ->setParameter('enabled', true)
            ->setParameter('slug', $slug);
    }

    private function assertValidPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page is outside the public Game Genre directory range.');
        }
    }
}
