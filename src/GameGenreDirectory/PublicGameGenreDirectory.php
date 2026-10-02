<?php

declare(strict_types=1);

namespace App\GameGenreDirectory;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGameGenreDirectory
{
    public const PAGE_SIZE = 24;
    public const MAX_PAGE = 100;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{items: list<array{slug: string, name: string, gameCount: int}>, hasMore: bool} */
    public function genres(int $page): array
    {
        $this->assertPage($page);
        $rows = $this->entityManager->createQueryBuilder()
            ->select('genre.slug AS slug', 'genre.name AS name', 'COUNT(DISTINCT entry.id) AS gameCount')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->join('entry.genres', 'genre')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true)
            ->groupBy('genre.id', 'genre.slug', 'genre.name')
            ->orderBy('genre.name', 'ASC')
            ->addOrderBy('genre.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE + 1)
            ->getQuery()
            ->getScalarResult();

        $hasMore = count($rows) > self::PAGE_SIZE;
        $items = [];
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            $items[] = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'gameCount' => (int) $row['gameCount'],
            ];
        }

        return ['items' => $items, 'hasMore' => $hasMore];
    }

    /** @return array{genre: GameGenre, entries: list<GameCatalogueEntry>, hasMore: bool}|null */
    public function genre(string $slug, int $page): ?array
    {
        $this->assertPage($page);

        $genre = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT genre')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->join('entry.genres', 'genre')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->andWhere('genre.slug = :slug')
            ->setParameter('enabled', true)
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$genre instanceof GameGenre) {
            return null;
        }

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT entry', 'game')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->join('entry.genres', 'genre')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->andWhere('genre = :genre')
            ->setParameter('enabled', true)
            ->setParameter('genre', $genre)
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('game.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE + 1)
            ->getQuery()
            ->getResult();
        $hasMore = count($entries) > self::PAGE_SIZE;

        return [
            'genre' => $genre,
            'entries' => array_slice($entries, 0, self::PAGE_SIZE),
            'hasMore' => $hasMore,
        ];
    }

    private function assertPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Invalid genre directory page.');
        }
    }
}
