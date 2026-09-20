<?php

declare(strict_types=1);

namespace App\Repository;

use App\ExternalConnector\ExternalConnectorTargetSource;
use App\Entity\ExternalConnectorTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExternalConnectorTarget> */
final class ExternalConnectorTargetRepository extends ServiceEntityRepository implements ExternalConnectorTargetSource
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalConnectorTarget::class);
    }

    /** @return list<ExternalConnectorTarget> */
    public function ordered(): array
    {
        return $this->findBy([], ['capability' => 'ASC', 'priority' => 'ASC', 'displayName' => 'ASC']);
    }

    /** @return list<ExternalConnectorTarget> */
    public function enabledFor(string $capability): array
    {
        return $this->findBy(
            ['capability' => $capability, 'enabled' => true],
            ['priority' => 'ASC', 'displayName' => 'ASC'],
        );
    }
}
