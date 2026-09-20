<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorHealthChecker;
use App\ExternalConnector\ExternalConnectorHealthRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:connectors:check-media-targets',
    description: 'Prüft alle aktiven Medienziele, ohne private Konfiguration auszugeben.',
)]
final class CheckMediaConnectorTargetsCommand extends Command
{
    public function __construct(
        private readonly ExternalConnectorHealthChecker $healthChecker,
        private readonly ExternalConnectorHealthRecorder $healthRecorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summary = $this->healthChecker->check(ExternalConnectorTarget::CAPABILITY_MEDIA);
        $this->healthRecorder->record($summary);

        if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED) {
            $output->writeln('<info>Keine aktiven Medienziele konfiguriert.</info>');

            return Command::SUCCESS;
        }

        foreach ($summary->results as $result) {
            $state = $result->successful ? '<info>erreichbar</info>' : '<error>nicht erreichbar</error>';
            $output->writeln(sprintf('%s: %s', $result->targetKey, $state));
        }

        $output->writeln(sprintf(
            'Gesamtstatus: %s (%d erfolgreich)',
            $summary->status(),
            $summary->successfulCount(),
        ));

        return $summary->status() === ExternalConnectorExecutionSummary::STATUS_HEALTHY
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
