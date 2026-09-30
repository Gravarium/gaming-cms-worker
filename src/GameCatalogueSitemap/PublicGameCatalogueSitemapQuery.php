<?php

declare(strict_types=1);

namespace App\GameCatalogueSitemap;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicGameCatalogueSitemapQuery
{
    public const PAGE_SIZE = 1_000;
    public const MAX_SITEMAP_PAGES = 50_000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countPublicGames(): int
    {
        return (int) $this->publicEntries()
            ->select('COUNT(entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<string> */
    public function findPublicGameSlugs(int $page): array
    {
        $this->assertValidPage($page);

        /** @var list<array{slug: string}> $rows */
        $rows = $this->publicEntries()
            ->select('game.slug AS slug')
            ->orderBy('LOWER(game.name)', 'ASC')
            ->addOrderBy('game.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): string => $row['slug'],
            $rows,
        );
    }

    private function publicEntries(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true);
    }

    private function assertValidPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_SITEMAP_PAGES) {
            throw new \InvalidArgumentException('Page is outside the public Game Catalogue sitemap range.');
        }
    }
}
