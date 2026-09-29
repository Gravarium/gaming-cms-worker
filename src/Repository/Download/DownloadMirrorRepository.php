<?php

declare(strict_types=1);

namespace App\Repository\Download;

use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DownloadMirror> */
final class DownloadMirrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadMirror::class);
    }

    /** @return list<DownloadMirror> */
    public function forVersion(DownloadVersion $version): array
    {
        return $this->findBy(['version' => $version], ['id' => 'ASC']);
    }

    /**
     * @param list<DownloadVersion> $versions
     * @return list<DownloadMirror>
     */
    public function forVersions(array $versions): array
    {
        if ($versions === []) {
            return [];
        }

        return $this->createQueryBuilder('mirror')
            ->addSelect('version')
            ->innerJoin('mirror.version', 'version')
            ->andWhere('mirror.version IN (:versions)')
            ->setParameter('versions', $versions)
            ->orderBy('version.id', 'DESC')
            ->addOrderBy('mirror.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsForVersionUrl(DownloadVersion $version, string $url): bool
    {
        return $this->count(['version' => $version, 'url' => $url]) > 0;
    }
}
