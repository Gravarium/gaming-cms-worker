<?php

declare(strict_types=1);

namespace App\Repository\Hardware;

use App\Entity\Hardware\HardwareProduct;
use App\Entity\Hardware\HardwareProductRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HardwareProductRevision> */
final class HardwareProductRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, HardwareProductRevision::class); }

    /** @return list<HardwareProductRevision> */
    public function forProduct(HardwareProduct $product): array
    {
        return $this->createQueryBuilder('revision')->leftJoin('revision.actor', 'actor')->addSelect('actor')
            ->andWhere('revision.product = :product')->setParameter('product', $product)
            ->orderBy('revision.version', 'DESC')->getQuery()->getResult();
    }

    public function nextVersion(HardwareProduct $product): int
    {
        return 1 + (int) $this->createQueryBuilder('revision')->select('COALESCE(MAX(revision.version), 0)')
            ->andWhere('revision.product = :product')->setParameter('product', $product)->getQuery()->getSingleScalarResult();
    }
}
