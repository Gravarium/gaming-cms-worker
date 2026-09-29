<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PublishScheduledContentCommand;
use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PublishScheduledContentCommandTest extends KernelTestCase
{
    public function testDueContentPublicationAndUnpublicationAreProcessedIdempotently(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->persistUser($entityManager, $suffix);
            $due = $this->dueTime();

            $scheduled = $this->entry($user, 'publish-'.$suffix, ContentEntry::STATUS_SCHEDULED)
                ->setScheduledAt($due);
            $unpublish = $this->entry($user, 'archive-'.$suffix, ContentEntry::STATUS_PUBLISHED)
                ->setPublishedAt($due->modify('-1 day'))
                ->setScheduledUnpublishAt($due);

            $entityManager->persist($scheduled);
            $entityManager->persist($unpublish);
            $entityManager->flush();
            $scheduledId = $scheduled->getId();
            $unpublishId = $unpublish->getId();
            self::assertNotNull($scheduledId);
            self::assertNotNull($unpublishId);

            $this->commandTester()->execute([]);

            $entityManager->clear();
            $storedScheduled = $entityManager->find(ContentEntry::class, $scheduledId);
            $storedUnpublish = $entityManager->find(ContentEntry::class, $unpublishId);
            self::assertInstanceOf(ContentEntry::class, $storedScheduled);
            self::assertInstanceOf(ContentEntry::class, $storedUnpublish);
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedScheduled->getStatus());
            self::assertSame($due->format('Y-m-d H:i:s'), $storedScheduled->getPublishedAt()?->format('Y-m-d H:i:s'));
            self::assertNull($storedScheduled->getScheduledAt());
            self::assertSame(ContentEntry::STATUS_ARCHIVED, $storedUnpublish->getStatus());
            self::assertNull($storedUnpublish->getScheduledUnpublishAt());

            $this->commandTester()->execute([]);
            $entityManager->clear();
            $storedScheduled = $entityManager->find(ContentEntry::class, $scheduledId);
            $storedUnpublish = $entityManager->find(ContentEntry::class, $unpublishId);
            self::assertInstanceOf(ContentEntry::class, $storedScheduled);
            self::assertInstanceOf(ContentEntry::class, $storedUnpublish);
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedScheduled->getStatus());
            self::assertSame(ContentEntry::STATUS_ARCHIVED, $storedUnpublish->getStatus());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testFailedDueReleaseDoesNotPreventHealthyReleaseFromBeingFlushed(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->persistUser($entityManager, $suffix);
            $due = $this->dueTime();

            $healthyEntry = $this->entry($user, 'healthy-'.$suffix, ContentEntry::STATUS_DRAFT);
            $trashedEntry = $this->entry($user, 'trashed-'.$suffix, ContentEntry::STATUS_TRASHED);
            $healthyRelease = (new ContentRelease())
                ->setName('Healthy release '.$suffix)
                ->setCreatedBy($user)
                ->setStatus(ContentRelease::STATUS_SCHEDULED)
                ->setScheduledAt($due)
                ->addEntry($healthyEntry);
            $failedRelease = (new ContentRelease())
                ->setName('Empty release '.$suffix)
                ->setCreatedBy($user)
                ->setStatus(ContentRelease::STATUS_SCHEDULED)
                ->setScheduledAt($due)
                ->addEntry($trashedEntry);

            foreach ([$healthyEntry, $trashedEntry, $healthyRelease, $failedRelease] as $record) {
                $entityManager->persist($record);
            }
            $entityManager->flush();

            $healthyEntryId = $healthyEntry->getId();
            $trashedEntryId = $trashedEntry->getId();
            $healthyReleaseId = $healthyRelease->getId();
            $failedReleaseId = $failedRelease->getId();
            self::assertNotNull($healthyEntryId);
            self::assertNotNull($trashedEntryId);
            self::assertNotNull($healthyReleaseId);
            self::assertNotNull($failedReleaseId);

            $tester = $this->commandTester();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString(
                sprintf('Release %d konnte nicht sicher veröffentlicht werden', $failedReleaseId),
                $tester->getDisplay(),
            );

            $entityManager->clear();
            $storedHealthyRelease = $entityManager->find(ContentRelease::class, $healthyReleaseId);
            $storedFailedRelease = $entityManager->find(ContentRelease::class, $failedReleaseId);
            $storedHealthyEntry = $entityManager->find(ContentEntry::class, $healthyEntryId);
            $storedTrashedEntry = $entityManager->find(ContentEntry::class, $trashedEntryId);
            self::assertInstanceOf(ContentRelease::class, $storedHealthyRelease);
            self::assertInstanceOf(ContentRelease::class, $storedFailedRelease);
            self::assertInstanceOf(ContentEntry::class, $storedHealthyEntry);
            self::assertInstanceOf(ContentEntry::class, $storedTrashedEntry);

            self::assertSame(ContentRelease::STATUS_PUBLISHED, $storedHealthyRelease->getStatus());
            self::assertNotNull($storedHealthyRelease->getPublishedAt());
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedHealthyEntry->getStatus());
            self::assertNotNull($storedHealthyEntry->getPublishedAt());
            self::assertSame(ContentRelease::STATUS_SCHEDULED, $storedFailedRelease->getStatus());
            self::assertSame($due->format('Y-m-d H:i:s'), $storedFailedRelease->getScheduledAt()?->format('Y-m-d H:i:s'));
            self::assertSame(ContentEntry::STATUS_TRASHED, $storedTrashedEntry->getStatus());
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
        $command = static::getContainer()->get(PublishScheduledContentCommand::class);
        self::assertInstanceOf(PublishScheduledContentCommand::class, $command);

        return new CommandTester($command);
    }

    private function persistUser(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('scheduled-command-'.$suffix.'@example.test')
            ->setDisplayName('Scheduled command test');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function entry(User $author, string $slug, string $status): ContentEntry
    {
        return (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Scheduled command '.$slug)
            ->setSlug('scheduled-'.$slug)
            ->setBody('Scheduled command test entry.')
            ->setStatus($status);
    }

    private function dueTime(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $secondPrecision = $now->setTime(
            (int) $now->format('H'),
            (int) $now->format('i'),
            (int) $now->format('s'),
        );

        return $secondPrecision->modify('-5 minutes');
    }
}
