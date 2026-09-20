<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MediaReplicationTaskRepository;
use App\Service\MediaReplicationRepairer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:media:repair-replicas',
    description: 'Wiederholt fehlgeschlagene optionale Medienkopien ohne private Anbieterdaten auszugeben.',
)]
final class RepairMediaReplicasCommand extends Command
{
    public function __construct(
        private readonly MediaReplicationTaskRepository $tasks,
        private readonly MediaReplicationRepairer $repairer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tasks = $this->tasks->pending();
        $successful = 0;

        foreach ($tasks as $task) {
            if ($this->repairer->repair($task)) {
                ++$successful;
            }
        }
        $this->entityManager->flush();

        $failed = count($tasks) - $successful;
        $output->writeln(sprintf('Medien-Reparatur: %d erfolgreich, %d weiterhin offen.', $successful, $failed));

        return Command::SUCCESS;
    }
}
