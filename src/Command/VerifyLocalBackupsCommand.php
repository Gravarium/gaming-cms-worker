<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\LocalBackupInventory;
use App\Backup\LocalBackupVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:backup:verify-local',
    description: 'Prüft ein lokales Backup vollständig und speichert nur den bereinigten Status.',
)]
final class VerifyLocalBackupsCommand extends Command
{
    public function __construct(
        private readonly LocalBackupInventory $inventory,
        private readonly LocalBackupVerifier $verifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('backup-id', InputArgument::OPTIONAL, 'Backup-ID; ohne Angabe wird das neueste Backup geprüft.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->inventory->read();
        if (!$snapshot['available']) {
            $output->writeln('<error>Der private Backup-Speicher ist nicht lesbar.</error>');
            return Command::FAILURE;
        }

        $backupId = trim((string) $input->getArgument('backup-id'));
        if ($backupId === '') {
            $latest = $snapshot['backups'][0] ?? null;
            if ($latest === null) {
                $output->writeln('<error>Kein lokales Backup gefunden.</error>');
                return Command::FAILURE;
            }
            $backupId = $latest['id'];
        } elseif (!in_array($backupId, array_column($snapshot['backups'], 'id'), true)) {
            $output->writeln('<error>Das ausgewählte Backup gehört nicht zum gültigen Bestand.</error>');
            return Command::FAILURE;
        }

        if (!$this->verifier->verify($backupId)) {
            $output->writeln(sprintf('<error>Backup-Prüfung fehlgeschlagen: %s</error>', $backupId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Backup vollständig geprüft: %s</info>', $backupId));
        return Command::SUCCESS;
    }
}
