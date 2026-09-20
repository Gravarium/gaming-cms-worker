<?php

declare(strict_types=1);

namespace App\Command;

use App\ExtensionPackage\ExtensionPackageInstaller;
use App\ExtensionPackage\ExtensionPackageVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:extensions:verify', description: 'Prüft ein signiertes Modul- oder Theme-Paket und installiert es optional atomar.')]
final class VerifyExtensionPackageCommand extends Command
{
    public function __construct(
        private readonly ExtensionPackageVerifier $verifier,
        private readonly ExtensionPackageInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Bereits sicher bereitgestelltes Paketverzeichnis')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Nach erfolgreicher Prüfung atomar installieren');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manifest = $input->getOption('install')
                ? $this->installer->install((string) $input->getArgument('directory'))
                : $this->verifier->verify((string) $input->getArgument('directory'));
        } catch (\DomainException|\RuntimeException $exception) {
            $output->writeln('<error>Paket abgelehnt: '.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%s-Paket %s %s ist signiert, kompatibel und vollständig%s.</info>',
            ucfirst($manifest->type),
            $manifest->key,
            $manifest->version,
            $input->getOption('install') ? ' installiert' : '',
        ));

        return Command::SUCCESS;
    }
}
