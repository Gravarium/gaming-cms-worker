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
    name: 'app:connectors:check-external-targets',
    description: 'Prüft aktive Mail-, Benachrichtigungs-, Identitäts-, CDN- und Analyseziele ohne private Daten auszugeben.',
)]
final class CheckExternalConnectorTargetsCommand extends Command
{
    private const CAPABILITIES = [
        ExternalConnectorTarget::CAPABILITY_MAIL,
        ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
        ExternalConnectorTarget::CAPABILITY_IDENTITY,
        ExternalConnectorTarget::CAPABILITY_CDN,
        ExternalConnectorTarget::CAPABILITY_ANALYTICS,
    ];

    public function __construct(
        private readonly ExternalConnectorHealthChecker $healthChecker,
        private readonly ExternalConnectorHealthRecorder $healthRecorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = false;
        foreach (self::CAPABILITIES as $capability) {
            $summary = $this->healthChecker->check($capability);
            $this->healthRecorder->record($summary);

            if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED) {
                $output->writeln(sprintf('<info>%s: keine aktiven Ziele.</info>', $capability));
                continue;
            }

            foreach ($summary->results as $result) {
                $output->writeln(sprintf(
                    '%s/%s: %s',
                    $capability,
                    $result->targetKey,
                    $result->successful ? '<info>erreichbar</info>' : '<error>nicht erreichbar</error>',
                ));
            }

            if ($summary->status() !== ExternalConnectorExecutionSummary::STATUS_HEALTHY) {
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
