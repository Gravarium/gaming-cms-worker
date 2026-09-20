<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MediaReplicationTaskRepository;
use App\Service\MediaDeletionRepairer;
use App\Service\MediaReplicationRepairer;
use App\Service\MediaStorageCleanupRepairer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:media:repair-replicas',
    description: 'Wiederholt fehlgeschlagene Medienkopien und sichere Storage-Cleanups ohne private Anbieterdaten auszugeben.',
)]
final class RepairMediaReplicasCommand extends Command
{
    public function __construct(
        private readonly MediaReplicationTaskRepository $tasks,
        private readonly MediaReplicationRepairer $repairer,
        private readonly MediaStorageCleanupRepairer $cleanupRepairer,
        private readonly MediaDeletionRepairer $deletionRepairer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tasks = $this->tasks->pending();
        $successful = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            $repaired = $this->repairer->repair($task);
            try {
                $this->entityManager->flush();
            } catch (\Throwable) {
                $output->writeln('<error>Medien-Reparatur konnte nicht sicher in der Datenbank bestätigt werden.</error>');

                return Command::FAILURE;
            }

            if ($repaired) {
                try {
                    $this->repairer->finalize($task);
                } catch (\RuntimeException) {
                    // A stale private staging file is safe; the database state is already repaired.
                }
                ++$successful;
            } else {
                ++$failed;
            }
        }

        $cleanup = $this->cleanupRepairer->repairPending();
        $deletions = $this->deletionRepairer->repairPending();

        $output->writeln(sprintf(
            'Medien-Reparatur: %d Replikate erfolgreich, %d offen; %d Cleanups erfolgreich, %d offen; %d Löschungen abgeschlossen, %d offen.',
            $successful,
            $failed,
            $cleanup['repaired'],
            $cleanup['failed'],
            $deletions['repaired'],
            $deletions['failed'],
        ));

        return $failed === 0 && $cleanup['failed'] === 0 && $deletions['failed'] === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
