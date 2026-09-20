<?php

declare(strict_types=1);

namespace App\Command;

use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionPackageVerifier;
use App\ExtensionPackage\ExtensionPermissionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:extensions:permission', description: 'Gewährt oder entzieht genau eine angeforderte Fähigkeit eines installierten signierten Pakets.')]
final class ExtensionPermissionCommand extends Command
{
    public function __construct(
        private readonly ExtensionPackageVerifier $verifier,
        private readonly ExtensionPermissionStore $permissions,
        private readonly ExtensionCapabilityPolicy $policy,
        private readonly string $installRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'module oder theme')
            ->addArgument('key', InputArgument::REQUIRED, 'Paketkennung')
            ->addArgument('capability', InputArgument::REQUIRED, 'Einzelne Fähigkeit')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Fähigkeit entziehen statt gewähren');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getArgument('type');
        $key = (string) $input->getArgument('key');
        $capability = (string) $input->getArgument('capability');

        if (!in_array($type, ['module', 'theme'], true)
            || preg_match('/^[a-z][a-z0-9-]{1,39}$/', $key) !== 1
            || !$this->policy->isGrantable($capability)
        ) {
            $output->writeln('<error>Ungültiger Paket- oder Fähigkeitswert.</error>');
            return Command::INVALID;
        }

        try {
            $manifest = $this->verifier->verify(rtrim($this->installRoot, '/').'/'.$type.'/'.$key);
            if ($input->getOption('revoke')) {
                $this->permissions->revoke($manifest, $capability);
                $output->writeln('<info>Fähigkeit entzogen.</info>');
            } else {
                $this->permissions->grant($manifest, $capability);
                $output->writeln('<info>Fähigkeit einzeln freigegeben.</info>');
            }
        } catch (\DomainException|\RuntimeException $exception) {
            $output->writeln('<error>Keine Änderung: '.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
