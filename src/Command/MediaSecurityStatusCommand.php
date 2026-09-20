<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MediaMalwareScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:media:security-status',
    description: 'Zeigt den bereinigten Status der zentralen Upload-Sicherheit.',
)]
final class MediaSecurityStatusCommand extends Command
{
    public function __construct(private readonly MediaMalwareScanner $scanner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->scanner->status();
        $output->writeln('Zentrale Dateityp-, Endungs-, Größen- und Dateinamensprüfung: <info>aktiv</info>');
        $output->writeln('Malware-Scanner: '.match ($status) {
            'ready' => '<info>bereit</info>',
            MediaMalwareScanner::MODE_OFF => '<comment>deaktiviert</comment>',
            'unavailable' => '<comment>nicht verfügbar</comment>',
            default => '<error>ungültig konfiguriert</error>',
        });

        return in_array($status, ['ready', MediaMalwareScanner::MODE_OFF], true)
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
