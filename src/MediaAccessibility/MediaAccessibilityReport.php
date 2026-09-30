<?php

declare(strict_types=1);

namespace App\MediaAccessibility;

use App\Entity\MediaAsset;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class MediaAccessibilityReport
{
    public const PAGE_SIZE = 25;
    public const MAX_PAGE = 10_000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     assets: list<MediaAsset>,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     pageSize: int,
     *     limitReached: bool
     * }
     */
    public function page(int $page): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Report page is outside the allowed range.');
        }

        $total = (int) $this->query()->select('COUNT(asset.id)')->getQuery()->getSingleScalarResult();
        $unboundedPages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $pages = min(self::MAX_PAGE, $unboundedPages);
        if ($page > $pages) {
            throw new \OutOfRangeException('Report page does not exist.');
        }

        $query = $this->query()
            ->select('asset')
            ->orderBy('asset.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        /** @var list<MediaAsset> $assets */
        $assets = $query->getQuery()->getResult();

        return [
            'assets' => $assets,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => self::PAGE_SIZE,
            'limitReached' => $unboundedPages > self::MAX_PAGE,
        ];
    }

    private function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(MediaAsset::class, 'asset')
            ->andWhere('asset.mimeType LIKE :imageMimeType')
            ->andWhere('(asset.altText IS NULL OR asset.altText = :emptyAltText)')
            ->andWhere('asset.deletionState = :activeDeletionState')
            ->setParameter('imageMimeType', 'image/%')
            ->setParameter('emptyAltText', '')
            ->setParameter('activeDeletionState', MediaAsset::DELETION_ACTIVE);
    }
}
