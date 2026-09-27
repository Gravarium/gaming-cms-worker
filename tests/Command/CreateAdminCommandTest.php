<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateAdminCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdminCommandTest extends KernelTestCase
{
    public function testRejectsEmailLongerThanPersistedColumnBeforePasswordPrompt(): void
    {
        self::bootKernel();

        $email = 'user@'.str_repeat('a', 59).'.'.str_repeat('b', 59).'.'.str_repeat('c', 59).'.com';
        self::assertGreaterThan(180, mb_strlen($email));
        self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));

        $tester = $this->commandTester();
        $tester->setInputs(['']);

        self::assertSame(Command::INVALID, $tester->execute([
            'email' => $email,
            'display-name' => 'Admin',
        ]));
        self::assertStringContainsString('E-Mail-Adresse darf höchstens 180 Zeichen lang sein.', $tester->getDisplay());
    }

    public function testRejectsDisplayNameLongerThanPersistedColumnBeforePasswordPrompt(): void
    {
        self::bootKernel();

        $tester = $this->commandTester();
        $tester->setInputs(['']);

        self::assertSame(Command::INVALID, $tester->execute([
            'email' => 'admin@example.test',
            'display-name' => str_repeat('A', 81),
        ]));
        self::assertStringContainsString('Anzeigename darf höchstens 80 Zeichen lang sein.', $tester->getDisplay());
    }

    public function testPersistsIdentityAtBothColumnLengthLimits(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $localPart = str_repeat('a', 48).bin2hex(random_bytes(8));
            $email = $localPart.'@'.str_repeat('b', 63).'.'.str_repeat('c', 47).'.com';
            $displayName = str_repeat('N', 80);
            self::assertSame(180, mb_strlen($email));
            self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));

            $tester = $this->commandTester();
            $tester->setInputs(['SafePassword123']);

            self::assertSame(Command::SUCCESS, $tester->execute([
                'email' => $email,
                'display-name' => $displayName,
            ]));
            self::assertStringContainsString('Administrator wurde erstellt.', $tester->getDisplay());

            $entityManager->clear();
            $storedUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(User::class, $storedUser);
            self::assertSame($email, $storedUser->getEmail());
            self::assertSame($displayName, $storedUser->getDisplayName());
            self::assertSame(80, mb_strlen($storedUser->getDisplayName()));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function commandTester(): CommandTester
    {
        $command = static::getContainer()->get(CreateAdminCommand::class);
        self::assertInstanceOf(CreateAdminCommand::class, $command);

        return new CommandTester($command);
    }
}
