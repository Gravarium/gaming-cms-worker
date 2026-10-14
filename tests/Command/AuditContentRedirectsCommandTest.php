<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AuditContentRedirectsCommand;
use App\ContentRedirect\ContentRedirectTargetAudit;
use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use App\Entity\User;
use App\Repository\ContentRedirectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AuditContentRedirectsCommandTest extends KernelTestCase
{
    public function testReportsUnpublishedTargetWithoutChangingRedirectData(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        $suffix = bin2hex(random_bytes(5));
        $user = (new User())->setEmail('redirect-audit-'.$suffix.'@example.test')
            ->setDisplayName('Redirect audit')->setPermissions([])->verifyEmail();
        $target = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_PAGE)
            ->setTitle('Draft target')->setSlug('draft-'.$suffix)->setBody('Draft body')
            ->setStatus(ContentEntry::STATUS_DRAFT);
        $redirect = new ContentRedirect($target, ContentEntry::TYPE_PAGE, 'legacy-'.$suffix);
        $entityManager->persist($user);
        $entityManager->persist($target);
        $entityManager->persist($redirect);
        $entityManager->flush();

        try {
            $command = new AuditContentRedirectsCommand(
                $container->get(ContentRedirectRepository::class),
                $container->get(ContentRedirectTargetAudit::class),
            );
            $tester = new CommandTester($command);

            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('/page/legacy-'.$suffix, $tester->getDisplay());
            self::assertStringContainsString('draft-'.$suffix, $tester->getDisplay());
            self::assertStringContainsString('keine Daten verändert', $tester->getDisplay());
            self::assertFalse($target->isPublished());
            self::assertSame('legacy-'.$suffix, $redirect->getSourceSlug());
        } finally {
            $connection->rollBack();
            $entityManager->clear();
        }
    }
}
