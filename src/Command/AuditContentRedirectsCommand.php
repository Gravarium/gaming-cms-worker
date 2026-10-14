<?php

declare(strict_types=1);

namespace App\Command;

use App\ContentRedirect\ContentRedirectTargetAudit;
use App\Entity\ContentRedirect;
use App\Repository\ContentRedirectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:content-redirect:audit', description: 'Prüft Content-Weiterleitungen auf unveröffentlichte Ziele.')]
final class AuditContentRedirectsCommand extends Command
{
    public function __construct(
        private readonly ContentRedirectRepository $redirects,
        private readonly ContentRedirectTargetAudit $audit,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<ContentRedirect> $redirects */
        $redirects = $this->redirects->findAll();
        $findings = $this->audit->findUnpublishedTargets($redirects);

        if ($findings === []) {
            $io->success('Alle Redirect-Ziele sind veröffentlicht.');

            return Command::SUCCESS;
        }

        foreach ($findings as $finding) {
            $io->warning(sprintf(
                'Unveröffentlichtes Ziel: /%s/%s → %s',
                $finding['type'],
                $finding['source_slug'],
                $finding['target_slug'],
            ));
        }

        $io->error(sprintf('%d Redirect-Ziele sind unveröffentlicht; es wurden keine Daten verändert.', count($findings)));

        return Command::FAILURE;
    }
}
