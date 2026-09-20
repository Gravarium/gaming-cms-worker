<?php

declare(strict_types=1);
namespace App\Command;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'app:content:publish-due', description: 'Verarbeitet fällige Veröffentlichungen, Depublikationen und Content-Releases idempotent.')]
final class PublishScheduledContentCommand extends Command
{
    public function __construct(private readonly ContentEntryRepository $entries, private readonly ContentReleaseRepository $releases, private readonly EntityManagerInterface $entityManager) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable(); $published = 0; $unpublished = 0; $releaseCount = 0; $releaseEntries = 0;
        foreach ($this->entries->findDueForPublication($now) as $entry) { if ($entry->publishIfDue($now)) { ++$published; } }
        foreach ($this->entries->findDueForUnpublication($now) as $entry) { if ($entry->unpublishIfDue($now)) { ++$unpublished; } }
        foreach ($this->releases->findDue($now) as $release) { if ($release->isDue($now)) { $releaseEntries += $release->publish($now); ++$releaseCount; } }
        $this->entityManager->flush();
        $output->writeln(sprintf('<info>%d veröffentlicht, %d archiviert, %d Releases mit %d Inhalten verarbeitet.</info>', $published, $unpublished, $releaseCount, $releaseEntries));
        return Command::SUCCESS;
    }
}
