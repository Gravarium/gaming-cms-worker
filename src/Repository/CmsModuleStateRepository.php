<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CmsModuleState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CmsModuleState> */
final class CmsModuleStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CmsModuleState::class); }

    /** @return array<string, CmsModuleState> */
    public function indexed(): array
    {
        $result = [];
        foreach ($this->findAll() as $state) { $result[$state->getModuleKey()] = $state; }
        return $result;
    }
}
