<?php

declare(strict_types=1);

namespace App\Repository\Download;

use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DownloadVersion> */
final class DownloadVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadVersion::class);
    }

    /** @return list<DownloadVersion> */
    public function forPackage(DownloadPackage $package): array
    {
        return $this->findBy(['package' => $package], ['id' => 'DESC']);
    }
}
