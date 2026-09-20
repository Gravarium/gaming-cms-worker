<?php

declare(strict_types=1);

namespace App\Command;

use App\ExternalConnector\BackupTargetSelectionExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:connectors:export-backup-selection',
    description: 'Exportiert die nicht geheime CMS-Auswahl für die externe Backup-Ausführung.',
)]
final class ExportBackupTargetSelectionCommand extends Command
{
    public function __construct(private readonly BackupTargetSelectionExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('output', InputArgument::REQUIRED, 'Absolute Zieldatei für die sichere Auswahl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $input->getArgument('output');
        if (!str_starts_with($target, '/') || basename($target) === '' || is_link($target)) {
            $output->writeln('<error>Die Zieldatei muss absolut sein und darf kein symbolischer Link sein.</error>');

            return Command::INVALID;
        }

        $parent = realpath(dirname($target));
        if ($parent === false || !is_dir($parent) || is_link(dirname($target))) {
            $output->writeln('<error>Das Zielverzeichnis muss bereits als echtes Verzeichnis existieren.</error>');

            return Command::INVALID;
        }

        try {
            $content = $this->exporter->export();
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $temporary = tempnam($parent, '.backup-selection.');
        if ($temporary === false) {
            $output->writeln('<error>Die temporäre Auswahldatei konnte nicht erstellt werden.</error>');

            return Command::FAILURE;
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $target)) {
                throw new \RuntimeException('Die Auswahldatei konnte nicht atomar gespeichert werden.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Backup-Zielauswahl wurde ohne Zugangsdaten exportiert.</info>');

        return Command::SUCCESS;
    }
}
