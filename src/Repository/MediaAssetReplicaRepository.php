<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaAssetReplica;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MediaAssetReplica> */
final class MediaAssetReplicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaAssetReplica::class);
    }
}
