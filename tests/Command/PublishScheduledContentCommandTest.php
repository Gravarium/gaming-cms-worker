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
    public function testDueContentPublicationAndUnpublicationAreProcessedAndIdempotent(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $suffix = bin2hex(random_bytes(5));
        $user = $this->persistUser($entityManager, $suffix);
        $due = (new \DateTimeImmutable())->modify('-5 minutes');

        $scheduled = $this->entry($user, 'publish-'.$suffix, ContentEntry::STATUS_SCHEDULED)
            ->setScheduledAt($due);
        $unpublish = $this->entry($user, 'archive-'.$suffix, ContentEntry::STATUS_PUBLISHED)
            ->setPublishedAt($due->modify('-1 day'))
            ->setScheduledUnpublishAt($due);

        $entityManager->persist($scheduled);
        $entityManager->persist($unpublish);
        $entityManager->flush();

        $userId = $user->getId();
        $scheduledId = $scheduled->getId();
        $unpublishId = $unpublish->getId();
        self::assertNotNull($userId);
        self::assertNotNull($scheduledId);
        self::assertNotNull($unpublishId);

        try {
            $tester = $this->commandTester();
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString(
                '1 veröffentlicht, 1 archiviert, 0 Releases mit 0 Inhalten verarbeitet; 0 Releases offen.',
                $tester->getDisplay(),
            );

            $entityManager->clear();
            $storedScheduled = $entityManager->find(ContentEntry::class, $scheduledId);
            $storedUnpublish = $entityManager->find(ContentEntry::class, $unpublishId);
            self::assertInstanceOf(ContentEntry::class, $storedScheduled);
            self::assertInstanceOf(ContentEntry::class, $storedUnpublish);
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedScheduled->getStatus());
            self::assertEquals($due, $storedScheduled->getPublishedAt());
            self::assertNull($storedScheduled->getScheduledAt());
            self::assertSame(ContentEntry::STATUS_ARCHIVED, $storedUnpublish->getStatus());
            self::assertNull($storedUnpublish->getScheduledUnpublishAt());

            $secondRun = $this->commandTester();
            self::assertSame(Command::SUCCESS, $secondRun->execute([]));
            self::assertStringContainsString('0 veröffentlicht, 0 archiviert', $secondRun->getDisplay());
        } finally {
            $this->removeFixtures($entityManager, [], [$scheduledId, $unpublishId], $userId);
        }
    }

    public function testFailedDueReleaseDoesNotPreventHealthyReleaseFromBeingFlushed(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $suffix = bin2hex(random_bytes(5));
        $user = $this->persistUser($entityManager, $suffix);
        $due = (new \DateTimeImmutable())->modify('-5 minutes');

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

        $userId = $user->getId();
        $healthyEntryId = $healthyEntry->getId();
        $trashedEntryId = $trashedEntry->getId();
        $healthyReleaseId = $healthyRelease->getId();
        $failedReleaseId = $failedRelease->getId();
        self::assertNotNull($userId);
        self::assertNotNull($healthyEntryId);
        self::assertNotNull($trashedEntryId);
        self::assertNotNull($healthyReleaseId);
        self::assertNotNull($failedReleaseId);

        try {
            $tester = $this->commandTester();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            $display = $tester->getDisplay();
            self::assertStringContainsString(
                sprintf('Release %d konnte nicht sicher veröffentlicht werden', $failedReleaseId),
                $display,
            );
            self::assertStringContainsString(
                '0 veröffentlicht, 0 archiviert, 1 Releases mit 1 Inhalten verarbeitet; 1 Releases offen.',
                $display,
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
            self::assertEquals($due, $storedFailedRelease->getScheduledAt());
            self::assertSame(ContentEntry::STATUS_TRASHED, $storedTrashedEntry->getStatus());
        } finally {
            $this->removeFixtures(
                $entityManager,
                [$healthyReleaseId, $failedReleaseId],
                [$healthyEntryId, $trashedEntryId],
                $userId,
            );
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

    /**
     * @param list<int> $releaseIds
     * @param list<int> $entryIds
     */
    private function removeFixtures(
        EntityManagerInterface $entityManager,
        array $releaseIds,
        array $entryIds,
        int $userId,
    ): void {
        $entityManager->clear();

        foreach ($releaseIds as $id) {
            $release = $entityManager->find(ContentRelease::class, $id);
            if ($release instanceof ContentRelease) {
                $entityManager->remove($release);
            }
        }

        foreach ($entryIds as $id) {
            $entry = $entityManager->find(ContentEntry::class, $id);
            if ($entry instanceof ContentEntry) {
                $entityManager->remove($entry);
            }
        }

        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }

        $entityManager->flush();
    }
}
