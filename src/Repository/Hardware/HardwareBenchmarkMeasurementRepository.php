<?php

declare(strict_types=1);

namespace App\Repository\Hardware;

use App\Entity\Hardware\HardwareBenchmarkMeasurement;
use App\Entity\Hardware\HardwareProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HardwareBenchmarkMeasurement> */
final class HardwareBenchmarkMeasurementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, HardwareBenchmarkMeasurement::class); }

    /** @param list<int> $productIds
     *  @return list<HardwareBenchmarkMeasurement>
     */
    public function publicForProducts(array $productIds): array
    {
        if ($productIds === []) { return []; }

        return $this->createQueryBuilder('measurement')->addSelect('methodology')
            ->join('measurement.methodology', 'methodology')->join('measurement.product', 'product')
            ->andWhere('product.id IN (:ids)')->andWhere('product.published = true')
            ->andWhere('measurement.sourceType = :source')->setParameter('source', 'editorial')->setParameter('ids', $productIds)
            ->orderBy('measurement.series', 'ASC')->addOrderBy('methodology.name', 'ASC')
            ->addOrderBy('measurement.measuredAt', 'DESC')->addOrderBy('measurement.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<HardwareBenchmarkMeasurement> */
    public function adminDirectory(): array
    {
        return $this->createQueryBuilder('measurement')->addSelect('product')->addSelect('methodology')
            ->join('measurement.product', 'product')->join('measurement.methodology', 'methodology')
            ->orderBy('measurement.measuredAt', 'DESC')->addOrderBy('measurement.id', 'DESC')
            ->setMaxResults(300)->getQuery()->getResult();
    }
}
