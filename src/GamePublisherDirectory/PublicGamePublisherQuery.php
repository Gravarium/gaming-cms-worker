<?php

declare(strict_types=1);

namespace App\GamePublisherDirectory;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GamePublisher;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGamePublisherQuery
{
    public const PAGE_SIZE = 24;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{publishers: list<GamePublisher>, total: int, totalPages: int}
     */
    public function publishers(int $page): array
    {
        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT publisher.id)')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.publisher', 'publisher')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = $total === 0 ? 1 : (int) ceil($total / self::PAGE_SIZE);

        /** @var list<GamePublisher> $publishers */
        $publishers = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT publisher')
            ->from(GamePublisher::class, 'publisher')
            ->join(GameCatalogueEntry::class, 'entry', 'WITH', 'entry.publisher = publisher')
            ->join('entry.game', 'game')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('publisher.name', 'ASC')
            ->addOrderBy('publisher.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return ['publishers' => $publishers, 'total' => $total, 'totalPages' => $totalPages];
    }

    /**
     * @return array{publisher: GamePublisher, entries: list<GameCatalogueEntry>, total: int, totalPages: int}|null
     */
    public function publisher(string $slug, int $page): ?array
    {
        $countBuilder = $this->entityManager->createQueryBuilder()
            ->select('COUNT(entry.id)')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.publisher', 'publisher')
            ->join('entry.game', 'game')
            ->andWhere('publisher.slug = :slug')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('slug', $slug)
            ->setParameter('enabled', true);

        $total = (int) $countBuilder->getQuery()->getSingleScalarResult();
        if ($total === 0) {
            return null;
        }

        $totalPages = (int) ceil($total / self::PAGE_SIZE);

        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('entry', 'game', 'publisher')
            ->from(GameCatalogueEntry::class, 'entry')
            ->join('entry.publisher', 'publisher')
            ->join('entry.game', 'game')
            ->andWhere('publisher.slug = :slug')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('slug', $slug)
            ->setParameter('enabled', true)
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('game.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        if ($entries === []) {
            return null;
        }

        $publisher = $entries[0]->getPublisher();
        if (!$publisher instanceof GamePublisher) {
            return null;
        }

        return ['publisher' => $publisher, 'entries' => $entries, 'total' => $total, 'totalPages' => $totalPages];
    }
}
