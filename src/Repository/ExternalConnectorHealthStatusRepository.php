<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalConnectorHealthStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExternalConnectorHealthStatus> */
final class ExternalConnectorHealthStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalConnectorHealthStatus::class);
    }

    /** @return array<string, ExternalConnectorHealthStatus> */
    public function forCapability(string $capability): array
    {
        $statuses = [];
        foreach ($this->findBy(['capability' => $capability]) as $status) {
            $statuses[$status->getTargetKey()] = $status;
        }

        return $statuses;
    }
}
