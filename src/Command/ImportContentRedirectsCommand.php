<?php

declare(strict_types=1);

namespace App\Command;

use App\ContentRedirect\ContentRedirectCsvImporter;
use App\Entity\ContentRedirect;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentRedirectRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:content-redirect:import', description: 'Importiert Redirects aus dem CSV-Export der Content-Verwaltung.')]
final class ImportContentRedirectsCommand extends Command
{
    public function __construct(
        private readonly ContentRedirectCsvImporter $importer,
        private readonly ContentEntryRepository $entries,
        private readonly ContentRedirectRepository $redirects,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Pfad zur CSV-Datei.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Änderungen tatsächlich speichern (Standard ist Dry-Run).')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Maximale Datensätze, höchstens 5000.', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('max-rows');
        if (!is_string($limit) || preg_match('/\A[1-9][0-9]{0,3}\z/', $limit) !== 1 || (int) $limit > 5000) {
            $io->error('--max-rows muss zwischen 1 und 5000 liegen.');

            return Command::INVALID;
        }

        $path = $input->getArgument('file');
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            $io->error('Die CSV-Datei ist nicht lesbar.');

            return Command::INVALID;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $io->error('Die CSV-Datei konnte nicht geöffnet werden.');

            return Command::FAILURE;
        }
        try {
            $rows = $this->importer->parse($stream, (int) $limit);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        } finally {
            fclose($stream);
        }

        $apply = (bool) $input->getOption('apply');
        $created = 0;
        $unchanged = 0;
        $unpublished = 0;
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            foreach ($rows as $row) {
                if ($row['status'] !== 'published') {
                    ++$unpublished;
                    continue;
                }
                $target = $this->entries->findPublishedBySlug($row['target_slug'], $row['type']);
                if ($target === null) {
                    throw new RuntimeException(sprintf('Kein veröffentlichter %s-Zielinhalt mit Slug „%s“.', $row['type'], $row['target_slug']));
                }
                if ($row['source_slug'] === $row['target_slug']) {
                    throw new RuntimeException(sprintf('Quell- und Zielslug sind identisch: „%s“.', $row['source_slug']));
                }
                $existing = $this->redirects->findTarget($row['type'], $row['source_slug']);
                if ($existing !== null) {
                    if ($existing->getEntry()->getId() !== $target->getId()) {
                        throw new RuntimeException(sprintf('Der Quellpfad „/%s/%s“ verweist bereits auf ein anderes Ziel.', $row['type'], $row['source_slug']));
                    }
                    ++$unchanged;
                    continue;
                }

                if ($apply) {
                    $this->entityManager->persist(new ContentRedirect($target, $row['type'], $row['source_slug']));
                }
                ++$created;
            }

            if ($apply) {
                $this->entityManager->flush();
                $connection->commit();
            } else {
                $connection->rollBack();
            }
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s: %d neu, %d unverändert, %d wegen unveröffentlichtem Ziel übersprungen.',
            $apply ? 'Import gespeichert' : 'Dry-Run (keine Änderungen gespeichert)',
            $created,
            $unchanged,
            $unpublished,
        ));

        return Command::SUCCESS;
    }
}
