<?php

declare(strict_types=1);
namespace App\Tests\Entity;
use App\Entity\ContentEntry;
use PHPUnit\Framework\TestCase;
final class ContentEntryWorkflowTest extends TestCase
{
    public function testFutureSchedulePublishesOnlyWhenDue(): void
    {
        $now = new \DateTimeImmutable('2026-09-19 12:00:00 UTC'); $scheduled = $now->modify('+10 minutes');
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt($scheduled);
        self::assertFalse($entry->publishIfDue($now)); self::assertTrue($entry->publishIfDue($scheduled)); self::assertSame(ContentEntry::STATUS_PUBLISHED, $entry->getStatus()); self::assertSame($scheduled, $entry->getPublishedAt()); self::assertNull($entry->getScheduledAt()); self::assertFalse($entry->publishIfDue($scheduled));
    }
    public function testManualPublicationClearsSchedule(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_PUBLISHED)->setScheduledAt(new \DateTimeImmutable('+1 day')); $entry->synchronizePublication(); self::assertTrue($entry->isPublished()); self::assertNull($entry->getScheduledAt());
    }
    public function testNonPublishedWorkflowClearsPublicationDates(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_REVIEW)->setPublishedAt(new \DateTimeImmutable('-1 day'))->setScheduledAt(new \DateTimeImmutable('+1 day'))->setScheduledUnpublishAt(new \DateTimeImmutable('+2 days')); $entry->synchronizePublication(); self::assertFalse($entry->isPublished()); self::assertNull($entry->getPublishedAt()); self::assertNull($entry->getScheduledAt()); self::assertNull($entry->getScheduledUnpublishAt());
    }
    public function testScheduleRequiresFutureDate(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt(new \DateTimeImmutable('-1 minute')); $this->expectException(\DomainException::class); $entry->synchronizePublication();
    }
    public function testScheduledEndMustFollowScheduledPublication(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt(new \DateTimeImmutable('+2 hours'))->setScheduledUnpublishAt(new \DateTimeImmutable('+1 hour')); $this->expectException(\DomainException::class); $entry->synchronizePublication();
    }
    public function testPublishedContentCanAutomaticallyArchive(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00 UTC'); $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_PUBLISHED)->setPublishedAt($now->modify('-1 day'))->setScheduledUnpublishAt($now); self::assertTrue($entry->unpublishIfDue($now)); self::assertSame(ContentEntry::STATUS_ARCHIVED, $entry->getStatus()); self::assertNull($entry->getScheduledUnpublishAt()); self::assertFalse($entry->unpublishIfDue($now));
    }
    public function testScheduledUnpublishDateImmediatelyEndsPublicVisibility(): void
    {
        $boundary = new \DateTimeImmutable();
        $expired = (new ContentEntry())->setStatus(ContentEntry::STATUS_PUBLISHED)->setPublishedAt($boundary->modify('-1 hour'))->setScheduledUnpublishAt($boundary);
        self::assertFalse($expired->isPublished());
        self::assertFalse($expired->isPubliclyListed());

        $future = (new ContentEntry())->setStatus(ContentEntry::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable('-1 hour'))->setScheduledUnpublishAt(new \DateTimeImmutable('+1 hour'));
        self::assertTrue($future->isPublished());
        self::assertTrue($future->isPubliclyListed());
    }
    public function testTrashIsFailClosedAndRestoreReturnsDraft(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_PUBLISHED)->setPublishedAt(new \DateTimeImmutable('-1 hour'))->setScheduledUnpublishAt(new \DateTimeImmutable('+1 day')); $entry->trash(); self::assertSame(ContentEntry::STATUS_TRASHED, $entry->getStatus()); self::assertNotNull($entry->getTrashedAt()); self::assertNull($entry->getPublishedAt()); self::assertNull($entry->getScheduledUnpublishAt()); $entry->restoreFromTrash(); self::assertSame(ContentEntry::STATUS_DRAFT, $entry->getStatus()); self::assertNull($entry->getTrashedAt());
    }
    public function testOnlyTrashedContentCanBeRestoredFromTrash(): void { $this->expectException(\DomainException::class); (new ContentEntry())->restoreFromTrash(); }
    public function testUnlistedPublicationIsNotPubliclyListed(): void
    {
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_PUBLISHED)->setUnlisted(true); $entry->synchronizePublication(); self::assertTrue($entry->isPublished()); self::assertFalse($entry->isPubliclyListed());
    }
    public function testReadingTimeHasSensibleMinimum(): void
    {
        self::assertSame(1, (new ContentEntry())->setBody('Kurzer Text')->estimatedReadingMinutes()); self::assertSame(2, (new ContentEntry())->setBody(implode(' ', array_fill(0, 221, 'wort')))->estimatedReadingMinutes());
    }
}
