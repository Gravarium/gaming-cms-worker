<?php

declare(strict_types=1);
namespace App\Tests\Entity;
use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
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
}
