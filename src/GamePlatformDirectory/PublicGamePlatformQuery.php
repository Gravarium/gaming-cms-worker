<?php

declare(strict_types=1);

namespace App\GamePlatformDirectory;

use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGamePlatformQuery
{
    public const PAGE_SIZE = 24;
    public const MAX_PAGE = 100;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{platforms: list<GamePlatform>, total: int, totalPages: int} */
    public function platforms(int $page): array
    {
        $this->assertPage($page);

        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT platform.id)')
            ->from(GameRelease::class, 'release')
            ->join('release.platform', 'platform')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->andWhere('release.status != :cancelled')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = min(self::MAX_PAGE, max(1, (int) ceil($total / self::PAGE_SIZE)));

        /** @var list<GamePlatform> $platforms */
        $platforms = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT platform')
            ->from(GamePlatform::class, 'platform')
            ->join(GameRelease::class, 'release', 'WITH', 'release.platform = platform')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->andWhere('release.status != :cancelled')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('enabled', true)
            ->orderBy('platform.name', 'ASC')
            ->addOrderBy('platform.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return ['platforms' => $platforms, 'total' => $total, 'totalPages' => $totalPages];
    }

    /** @return array{platform: GamePlatform, releases: list<GameRelease>, total: int, totalPages: int}|null */
    public function platform(string $slug, int $page): ?array
    {
        $this->assertPage($page);

        $base = $this->entityManager->createQueryBuilder()
            ->from(GameRelease::class, 'release')
            ->join('release.platform', 'platform')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->andWhere('platform.slug = :slug')
            ->andWhere('release.status != :cancelled')
            ->andWhere('entry.enabled = :enabled')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('slug', $slug)
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('enabled', true);

        $count = clone $base;
        $total = (int) $count->select('COUNT(release.id)')->getQuery()->getSingleScalarResult();
        if ($total === 0) {
            return null;
        }

        $totalPages = min(self::MAX_PAGE, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $totalPages) {
            return null;
        }

        /** @var list<GameRelease> $releases */
        $releases = $base
            ->select('release', 'platform', 'entry', 'game', 'edition')
            ->leftJoin('release.edition', 'edition')
            ->orderBy('release.releaseAt', 'DESC')
            ->addOrderBy('game.name', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        if ($releases === []) {
            return null;
        }

        return [
            'platform' => $releases[0]->getPlatform(),
            'releases' => $releases,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    private function assertPage(int $page): void
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Page outside the public platform directory window.');
        }
    }
}
