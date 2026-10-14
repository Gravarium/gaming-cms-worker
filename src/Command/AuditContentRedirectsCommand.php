<?php

declare(strict_types=1);

namespace App\Command;

use App\ContentRedirect\ContentRedirectChainAnalyzer;
use App\Entity\ContentRedirect;
use App\Repository\ContentRedirectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:content-redirect:audit', description: 'Prüft Content-Weiterleitungen auf Ketten und Zyklen.')]
final class AuditContentRedirectsCommand extends Command
{
    public function __construct(
        private readonly ContentRedirectRepository $redirects,
        private readonly ContentRedirectChainAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<ContentRedirect> $redirects */
        $redirects = $this->redirects->findAll();
        $findings = $this->analyzer->analyze($redirects);

        if ($findings === []) {
            $io->success('Keine Redirect-Ketten oder -Zyklen gefunden.');

            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $path = implode(' → ', array_map(
                static fn (string $slug): string => '/'.$finding['type'].'/'.$slug,
                $finding['path'],
            ));
            $io->warning(sprintf(
                '%s: %s (%s)',
                $finding['cycle'] ? 'Zyklus' : 'Kette',
                $path,
                $finding['cycle'] ? 'Redirect-Schleife' : 'mehrere Weiterleitungen',
            ));
        }

        $io->error(sprintf('%d problematische Redirect-Pfade gefunden; es wurden keine Daten verändert.', count($findings)));

        return Command::FAILURE;
    }
}
