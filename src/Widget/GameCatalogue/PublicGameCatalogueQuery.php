<?php

declare(strict_types=1);

namespace App\Widget\GameCatalogue;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGameCatalogueQuery
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_ITEMS = 12;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<GameCatalogueEntry> */
    public function findPublic(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_ITEMS, $limit));

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('entry', 'game')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('game.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
