<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BackupVerificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BackupVerificationStatus> */
final class BackupVerificationStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupVerificationStatus::class);
    }

    /** @return array<string, BackupVerificationStatus> */
    public function indexed(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $status) {
            $indexed[$status->getBackupId()] = $status;
        }

        return $indexed;
    }
}
