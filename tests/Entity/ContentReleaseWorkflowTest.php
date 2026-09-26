<?php

declare(strict_types=1);
namespace App\Tests\Entity;
use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
final class ContentReleaseWorkflowTest extends TestCase
{
    public function testScheduledReleaseRequiresEntriesAndFutureTime(): void
    {
        $release = (new ContentRelease())->setStatus(ContentRelease::STATUS_SCHEDULED)->setScheduledAt(new \DateTimeImmutable('+1 hour')); $this->expectException(\DomainException::class); $release->synchronizeSchedule();
    }
    public function testReleasePublishesEntriesTogetherButSkipsTrash(): void
    {
        $first = (new ContentEntry())->setTitle('A')->setStatus(ContentEntry::STATUS_DRAFT); $trashed = (new ContentEntry())->setTitle('B'); $trashed->trash(); $release = (new ContentRelease())->addEntry($first)->addEntry($trashed); $now = new \DateTimeImmutable('2026-09-20 12:00:00 UTC');
        self::assertSame(1, $release->publish($now)); self::assertSame(ContentRelease::STATUS_PUBLISHED, $release->getStatus()); self::assertSame(ContentEntry::STATUS_PUBLISHED, $first->getStatus()); self::assertSame($now, $first->getPublishedAt()); self::assertSame(ContentEntry::STATUS_TRASHED, $trashed->getStatus()); self::assertSame(0, $release->publish($now));
    }
    public function testCancelledReleaseDoesNotPublish(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_DRAFT); $release = (new ContentRelease())->setStatus(ContentRelease::STATUS_CANCELLED)->addEntry($entry); self::assertSame(0, $release->publish(new \DateTimeImmutable())); self::assertSame(ContentEntry::STATUS_DRAFT, $entry->getStatus());
    }

    public function testReleaseWithOnlyTrashedEntriesCannotClaimSuccessfulPublication(): void
    {
        $trashed = new ContentEntry();
        $trashed->trash();
        $release = (new ContentRelease())->addEntry($trashed);

        $this->expectException(\DomainException::class);
        $release->publish(new \DateTimeImmutable());
    }

    public function testPublishedReleaseRejectsMutationThroughSettersAndAssociations(): void
    {
        $creator = new User();
        $entry = (new ContentEntry())->setTitle('Published entry');
        $otherEntry = (new ContentEntry())->setTitle('New entry');
        $publishedAt = new \DateTimeImmutable('2026-09-26 12:00:00 UTC');
        $release = (new ContentRelease())
            ->setName('Published release')
            ->setDescription('Historical release')
            ->setCreatedBy($creator)
            ->addEntry($entry);

        self::assertSame(1, $release->publish($publishedAt));

        $mutations = [
            fn () => $release->setName('Changed release'),
            fn () => $release->setDescription('Changed description'),
            fn () => $release->setScheduledAt(new \DateTimeImmutable('+1 hour')),
            fn () => $release->setCreatedBy(new User()),
            fn () => $release->setStatus(ContentRelease::STATUS_CANCELLED),
            fn () => $release->addEntry($otherEntry),
            fn () => $release->removeEntry($entry),
        ];

        foreach ($mutations as $mutation) {
            try {
                $mutation();
                self::fail('A published release must reject later mutation.');
            } catch (\DomainException $exception) {
                self::assertSame('Veröffentlichte Releases sind unveränderlich.', $exception->getMessage());
            }
        }

        $release->getEntries()->clear();

        self::assertSame('Published release', $release->getName());
        self::assertSame('Historical release', $release->getDescription());
        self::assertSame($creator, $release->getCreatedBy());
        self::assertSame(ContentRelease::STATUS_PUBLISHED, $release->getStatus());
        self::assertSame($publishedAt, $release->getPublishedAt());
        self::assertNull($release->getScheduledAt());
        self::assertCount(1, $release->getEntries());
        self::assertSame($entry, $release->getEntries()->first());
    }

    public function testReleaseCannotSkipPublicationWorkflowBySettingPublishedStatus(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ein Release muss über den Veröffentlichungsablauf veröffentlicht werden.');

        (new ContentRelease())->setStatus(ContentRelease::STATUS_PUBLISHED);
    }


}
