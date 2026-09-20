<?php
declare(strict_types=1);
namespace App\Command;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'app:content:publish-due', description: 'Veröffentlicht fällige, zuvor freigegebene Inhalte idempotent.')]
final class PublishScheduledContentCommand extends Command
{
    public function __construct(private readonly ContentEntryRepository $entries, private readonly EntityManagerInterface $entityManager) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable(); $published = 0;
        foreach ($this->entries->findDueForPublication($now) as $entry) {
            if ($entry->publishIfDue($now)) { ++$published; }
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('<info>%d geplante Inhalte veröffentlicht.</info>', $published));
        return Command::SUCCESS;
    }
}
