<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicGameCatalogueSearchRepository
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countMatches(string $query): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }

        return (int) $this->matchingEntries($query)
            ->select('COUNT(DISTINCT entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<GameCatalogueEntry> */
    public function findMatches(string $query, int $page): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page must be within the supported Game Catalogue search range.');
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->matchingEntries($query)
            ->select('DISTINCT entry', 'game', 'publisher')
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    private function matchingEntries(string $query): QueryBuilder
    {
        $needle = mb_strtolower($query, 'UTF-8');

        return $this->entityManager->createQueryBuilder()
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->leftJoin('entry.publisher', 'publisher')
            ->leftJoin('entry.genres', 'genre')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->andWhere(
                '(LOCATE(:needle, LOWER(game.name)) > 0'
                .' OR LOCATE(:needle, LOWER(game.description)) > 0'
                .' OR LOCATE(:needle, LOWER(entry.summary)) > 0'
                .' OR LOCATE(:needle, LOWER(entry.developer)) > 0'
                .' OR LOCATE(:needle, LOWER(publisher.name)) > 0'
                .' OR LOCATE(:needle, LOWER(genre.name)) > 0)',
            )
            ->setParameter('enabled', true)
            ->setParameter('needle', $needle);
    }
}
