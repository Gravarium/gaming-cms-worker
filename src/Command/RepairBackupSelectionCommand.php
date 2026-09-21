<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\BackupSelectionSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:connectors:repair-backup-selection',
    description: 'Synchronisiert eine ausstehende nicht geheime Backup-Zielauswahl erneut aus dem CMS-Zustand.',
)]
final class RepairBackupSelectionCommand extends Command
{
    public function __construct(private readonly BackupSelectionSynchronizer $selection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->selection->synchronize();
        } catch (\Throwable $exception) {
            $output->writeln('<error>Die Backup-Zielauswahl konnte nicht repariert werden.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Backup-Zielauswahl wurde aus dem aktuellen CMS-Zustand synchronisiert.</info>');

        return Command::SUCCESS;
    }
}
