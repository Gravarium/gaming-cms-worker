<?php

declare(strict_types=1);

namespace App\Backup;

use App\Entity\BackupVerificationStatus;
use App\Repository\BackupVerificationStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final readonly class LocalBackupVerifier
{
    public function __construct(
        private LocalBackupInventory $inventory,
        private BackupVerificationStatusRepository $statuses,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function verify(string $backupId): bool
    {
        $process = new Process(
            ['bash', $this->projectDir.'/bin/verify-backup', $this->inventory->directory($backupId)],
            $this->projectDir,
            ['APP_DIR' => $this->projectDir, 'BACKUP_EXPECTED_ID' => $backupId],
            null,
            600,
        );
        $process->run();

        $status = $this->statuses->findOneBy(['backupId' => $backupId]) ?? (new BackupVerificationStatus())->setBackupId($backupId);
        $status->setSuccessful($process->isSuccessful())->setCheckedAt(new \DateTimeImmutable());
        $this->entityManager->persist($status);
        $this->entityManager->flush();

        return $process->isSuccessful();
    }
}
