<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MediaAsset> */
final class MediaAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaAsset::class);
    }

    /** @return list<MediaAsset> */
    public function searchLibrary(?string $query, ?string $moduleKey, ?string $storageMode, ?string $mediaType, ?MediaFolder $folder = null, bool $withoutFolder = false): array
    {
        return $this->libraryQueryBuilder($query, $moduleKey, $storageMode, $mediaType, $folder, $withoutFolder)
            ->orderBy('asset.updatedAt', 'DESC')
            ->addOrderBy('asset.id', 'DESC')
            ->setMaxResults(250)
            ->getQuery()
            ->getResult();
    }

    /**
     * Return one deterministic page of the complete active media library.
     *
     * @return array{assets: list<MediaAsset>, total: int, page: int, perPage: int, pageCount: int}
     */
    public function searchLibraryPage(
        ?string $query,
        ?string $moduleKey,
        ?string $storageMode,
        ?string $mediaType,
        ?MediaFolder $folder = null,
        bool $withoutFolder = false,
        int $page = 1,
        int $perPage = 24,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $builder = $this->libraryQueryBuilder($query, $moduleKey, $storageMode, $mediaType, $folder, $withoutFolder);
        $countBuilder = clone $builder;
        $total = (int) $countBuilder
            ->select('COUNT(asset.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);

        /** @var list<MediaAsset> $assets */
        $assets = $builder
            ->orderBy('asset.updatedAt', 'DESC')
            ->addOrderBy('asset.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'assets' => $assets,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => $pageCount,
        ];
    }

    private function libraryQueryBuilder(
        ?string $query,
        ?string $moduleKey,
        ?string $storageMode,
        ?string $mediaType,
        ?MediaFolder $folder,
        bool $withoutFolder,
    ): QueryBuilder {
        $builder = $this->createQueryBuilder('asset')
            ->leftJoin('asset.folder', 'folder')->addSelect('folder')
            ->andWhere('asset.deletionState = :active')
            ->setParameter('active', MediaAsset::DELETION_ACTIVE);

        $query = trim((string) $query);
        if ($query !== '') {
            $builder
                ->andWhere('LOWER(asset.originalName) LIKE :query OR LOWER(asset.title) LIKE :query OR LOWER(asset.altText) LIKE :query OR LOWER(asset.caption) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if ($moduleKey !== null) {
            $builder->andWhere('asset.moduleKey = :module')->setParameter('module', $moduleKey);
        }
        if ($storageMode !== null) {
            $builder->andWhere('asset.storageMode = :storage')->setParameter('storage', $storageMode);
        }
        if ($folder !== null) {
            $builder->andWhere('asset.folder = :folder')->setParameter('folder', $folder);
        } elseif ($withoutFolder) {
            $builder->andWhere('asset.folder IS NULL');
        }
        if ($mediaType === 'image') {
            $builder->andWhere('asset.mimeType LIKE :mime')->setParameter('mime', 'image/%');
        } elseif ($mediaType === 'video') {
            $builder->andWhere('asset.mimeType LIKE :mime')->setParameter('mime', 'video/%');
        } elseif ($mediaType === 'other') {
            $builder->andWhere('(asset.mimeType IS NULL OR (asset.mimeType NOT LIKE :image AND asset.mimeType NOT LIKE :video))')
                ->setParameter('image', 'image/%')->setParameter('video', 'video/%');
        }

        return $builder;
    }

    public function findDuplicate(string $checksum, int $size): ?MediaAsset
    {
        return $this->findOneBy([
            'checksumSha256' => $checksum,
            'fileSize' => $size,
            'deletionState' => MediaAsset::DELETION_ACTIVE,
        ]);
    }

    /** @return list<MediaAsset> */
    public function pendingDeletion(int $limit = 100): array
    {
        return $this->createQueryBuilder('asset')
            ->andWhere('asset.deletionState = :pending')
            ->setParameter('pending', MediaAsset::DELETION_PENDING)
            ->orderBy('asset.deletionRequestedAt', 'ASC')
            ->addOrderBy('asset.id', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
