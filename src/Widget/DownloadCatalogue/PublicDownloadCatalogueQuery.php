<?php

declare(strict_types=1);

namespace App\Widget\DownloadCatalogue;

use App\Downloads\DownloadModuleAvailability;
use App\Entity\Download\DownloadPackage;
use App\Repository\Download\DownloadPackageRepository;

final readonly class PublicDownloadCatalogueQuery
{
    public const MAX_ITEMS = 12;

    public function __construct(
        private DownloadPackageRepository $packages,
        private DownloadModuleAvailability $availability,
    ) {
    }

    /**
     * @return list<DownloadPackage>
     */
    public function findPublicPackages(int $limit = 6): array
    {
        if (!$this->availability->enabled()) {
            return [];
        }

        $limit = max(1, min(self::MAX_ITEMS, $limit));

        /** @var list<DownloadPackage> $packages */
        $packages = $this->packages->createQueryBuilder('package')
            ->andWhere('package.enabled = :enabled')
            ->andWhere('package.visibility = :visibility')
            ->setParameter('enabled', true)
            ->setParameter('visibility', 'public')
            ->orderBy('package.title', 'ASC')
            ->addOrderBy('package.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $packages;
    }
}
