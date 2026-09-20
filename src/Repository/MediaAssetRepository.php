<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        $builder = $this->createQueryBuilder('asset')
            ->leftJoin('asset.folder', 'folder')->addSelect('folder')
            ->orderBy('asset.updatedAt', 'DESC')
            ->setMaxResults(250);

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

        return $builder->getQuery()->getResult();
    }

    public function findDuplicate(string $checksum, int $size): ?MediaAsset
    {
        return $this->findOneBy(['checksumSha256' => $checksum, 'fileSize' => $size]);
    }
}
