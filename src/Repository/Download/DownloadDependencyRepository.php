<?php

declare(strict_types=1);

namespace App\Repository\Download;

use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DownloadDependency> */
final class DownloadDependencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadDependency::class);
    }

    /** @return list<DownloadDependency> */
    public function forVersion(DownloadVersion $version): array
    {
        return $this->createQueryBuilder('dependency')
            ->addSelect('target')
            ->innerJoin('dependency.targetPackage', 'target')
            ->andWhere('dependency.version = :version')
            ->setParameter('version', $version)
            ->orderBy('dependency.kind', 'ASC')
            ->addOrderBy('target.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<DownloadVersion> $versions
     * @return list<DownloadDependency>
     */
    public function forVersions(array $versions): array
    {
        if ($versions === []) {
            return [];
        }

        return $this->createQueryBuilder('dependency')
            ->addSelect('target', 'version')
            ->innerJoin('dependency.targetPackage', 'target')
            ->innerJoin('dependency.version', 'version')
            ->andWhere('dependency.version IN (:versions)')
            ->setParameter('versions', $versions)
            ->orderBy('version.id', 'DESC')
            ->addOrderBy('dependency.kind', 'ASC')
            ->addOrderBy('target.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsForVersionTarget(DownloadVersion $version, DownloadPackage $target): bool
    {
        return $this->count(['version' => $version, 'targetPackage' => $target]) > 0;
    }

    /**
     * @return list<int>
     */
    public function requiredTargetPackageIdsForPackageId(int $packageId, int $limit): array
    {
        /** @var list<array{targetId: int|string}> $rows */
        $rows = $this->createQueryBuilder('dependency')
            ->select('IDENTITY(dependency.targetPackage) AS targetId')
            ->innerJoin('dependency.version', 'version')
            ->andWhere('IDENTITY(version.package) = :packageId')
            ->andWhere('dependency.kind = :kind')
            ->setParameter('packageId', $packageId)
            ->setParameter('kind', DownloadDependency::KIND_REQUIRES)
            ->orderBy('dependency.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): int => (int) $row['targetId'],
            $rows,
        );
    }
}
