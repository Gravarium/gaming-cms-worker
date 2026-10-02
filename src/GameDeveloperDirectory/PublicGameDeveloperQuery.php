<?php

declare(strict_types=1);

namespace App\GameDeveloperDirectory;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGameDeveloperQuery
{
    public const PAGE_SIZE = 24;
    public const MAX_PAGE = 100;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{developers: list<string>, total: int, totalPages: int} */
    public function developers(int $page): array
    {
        $this->assertPage($page);

        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT LOWER(entry.developer))')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.developer IS NOT NULL')
            ->andWhere("TRIM(entry.developer) != ''")
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = min(self::MAX_PAGE, max(1, (int) ceil($total / self::PAGE_SIZE)));

        /** @var list<array{developer: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('MIN(entry.developer) AS developer')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.developer IS NOT NULL')
            ->andWhere("TRIM(entry.developer) != ''")
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true)
            ->groupBy('LOWER(entry.developer)')
            ->orderBy('LOWER(entry.developer)', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getArrayResult();

        return [
            'developers' => array_values(array_map(static fn (array $row): string => $row['developer'], $rows)),
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    /** @return array{developer: string, entries: list<GameCatalogueEntry>, total: int, totalPages: int}|null */
    public function developer(string $developer, int $page): ?array
    {
        $this->assertPage($page);
        $developer = $this->normalizeDeveloper($developer);

        $base = $this->entityManager->createQueryBuilder()
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->andWhere('LOWER(entry.developer) = :developer')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('developer', mb_strtolower($developer, 'UTF-8'))
            ->setParameter('enabled', true);

        $count = clone $base;
        $total = (int) $count->select('COUNT(entry.id)')->getQuery()->getSingleScalarResult();
        if ($total === 0) {
            return null;
        }

        $totalPages = min(self::MAX_PAGE, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $totalPages) {
            return null;
        }

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $base
            ->select('entry', 'game', 'publisher')
            ->leftJoin('entry.publisher', 'publisher')
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('game.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        if ($entries === []) {
            return null;
        }

        return [
            'developer' => $entries[0]->getDeveloper() ?? $developer,
            'entries' => $entries,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    private function normalizeDeveloper(string $developer): string
    {
        if (!mb_check_encoding($developer, 'UTF-8') || strlen($developer) > 640) {
            throw new \InvalidArgumentException('Invalid developer name.');
        }

        $developer = trim($developer);
        if ($developer === '' || mb_strlen($developer, 'UTF-8') > 160 || preg_match('/[\x00-\x1F\x7F]/u', $developer) === 1) {
            throw new \InvalidArgumentException('Invalid developer name.');
        }

        return $developer;
    }

    private function assertPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page outside the public developer directory window.');
        }
    }
}
