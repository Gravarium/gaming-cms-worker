<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaAsset;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ContentMediaPickerRepository
{
    private const SELECTABLE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];

    public const PAGE_SIZE = 24;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countSelectable(string $query): int
    {
        return (int) $this->candidateQuery($query)
            ->select('COUNT(asset.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<MediaAsset>
     */
    public function findSelectablePage(string $query, int $page): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * self::PAGE_SIZE;

        /** @var list<MediaAsset> $assets */
        $assets = $this->candidateQuery($query)
            ->select('asset')
            ->orderBy('asset.title', 'ASC')
            ->addOrderBy('asset.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $assets;
    }

    private function candidateQuery(string $query): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(MediaAsset::class, 'asset')
            ->andWhere('asset.moduleKey = :module')
            ->andWhere('asset.deletionState = :active')
            ->andWhere('asset.mimeType IN (:mimeTypes)')
            ->andWhere('(asset.location LIKE :internalLocation OR asset.location LIKE :httpsLocation)')
            ->setParameter('module', 'content')
            ->setParameter('active', MediaAsset::DELETION_ACTIVE)
            ->setParameter('mimeTypes', self::SELECTABLE_MIME_TYPES)
            ->setParameter('internalLocation', '/uploads/media/%')
            ->setParameter('httpsLocation', 'https://%');

        $query = mb_strtolower(trim($query));
        if ($query !== '') {
            $builder
                ->andWhere('(LOWER(asset.title) LIKE :query OR LOWER(asset.originalName) LIKE :query OR LOWER(asset.altText) LIKE :query OR LOWER(asset.caption) LIKE :query)')
                ->setParameter('query', '%'.$query.'%');
        }

        return $builder;
    }
}
