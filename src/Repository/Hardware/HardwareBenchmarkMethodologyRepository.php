<?php

declare(strict_types=1);

namespace App\Repository\Hardware;

use App\Entity\Hardware\HardwareBenchmarkMethodology;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HardwareBenchmarkMethodology> */
final class HardwareBenchmarkMethodologyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, HardwareBenchmarkMethodology::class); }

    /** @return list<HardwareBenchmarkMethodology> */
    public function adminDirectory(): array
    {
        return $this->createQueryBuilder('methodology')->orderBy('methodology.name', 'ASC')->addOrderBy('methodology.version', 'DESC')
            ->setMaxResults(200)->getQuery()->getResult();
    }
}
