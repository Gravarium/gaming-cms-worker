<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ModuleStorageSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ModuleStorageSetting> */
final class ModuleStorageSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ModuleStorageSetting::class); }

    public function modeFor(string $moduleKey): string
    {
        return $this->findOneBy(['moduleKey' => $moduleKey])?->getStorageMode()
            ?? ModuleStorageSetting::MODE_INTERNAL;
    }
}
