<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create-admin',
    description: 'Erstellt einen Administrator für den geschützten CMS-Bereich.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse')
            ->addArgument('display-name', InputArgument::REQUIRED, 'Anzeigename');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $displayName = trim((string) $input->getArgument('display-name'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Die E-Mail-Adresse ist ungültig.</error>');

            return Command::INVALID;
        }

        if ($displayName === '') {
            $output->writeln('<error>Der Anzeigename darf nicht leer sein.</error>');

            return Command::INVALID;
        }

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            $output->writeln('<error>Für diese E-Mail-Adresse existiert bereits ein Konto.</error>');

            return Command::FAILURE;
        }

        $question = (new Question('Passwort: '))
            ->setHidden(true)
            ->setHiddenFallback(false);
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $password = (string) $questionHelper->ask($input, $output, $question);

        if (mb_strlen($password) < 12) {
            $output->writeln('<error>Das Passwort muss mindestens 12 Zeichen lang sein.</error>');

            return Command::INVALID;
        }

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($displayName)
            ->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('<info>Administrator wurde erstellt.</info>');

        return Command::SUCCESS;
    }
}
