<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ContentEntry;
use PHPUnit\Framework\TestCase;

final class ContentEntryWorkflowTest extends TestCase
{
    public function testFutureSchedulePublishesOnlyWhenDue(): void
    {
        $now = new \DateTimeImmutable('2026-09-19 12:00:00 UTC');
        $scheduled = $now->modify('+10 minutes');
        $entry = (new ContentEntry())->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt($scheduled);

        self::assertFalse($entry->publishIfDue($now));
        self::assertTrue($entry->publishIfDue($scheduled));
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $entry->getStatus());
        self::assertSame($scheduled, $entry->getPublishedAt());
        self::assertNull($entry->getScheduledAt());
        self::assertFalse($entry->publishIfDue($scheduled));
    }

    public function testManualPublicationClearsSchedule(): void
    {
        $entry = (new ContentEntry())
            ->setStatus(ContentEntry::STATUS_PUBLISHED)
            ->setScheduledAt(new \DateTimeImmutable('+1 day'));

        $entry->synchronizePublication();

        self::assertTrue($entry->isPublished());
        self::assertNull($entry->getScheduledAt());
    }

    public function testNonPublishedWorkflowClearsPublicationDates(): void
    {
        $entry = (new ContentEntry())
            ->setStatus(ContentEntry::STATUS_REVIEW)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'))
            ->setScheduledAt(new \DateTimeImmutable('+1 day'));

        $entry->synchronizePublication();

        self::assertFalse($entry->isPublished());
        self::assertNull($entry->getPublishedAt());
        self::assertNull($entry->getScheduledAt());
    }

    public function testScheduleRequiresFutureDate(): void
    {
        $entry = (new ContentEntry())
            ->setStatus(ContentEntry::STATUS_SCHEDULED)
            ->setScheduledAt(new \DateTimeImmutable('-1 minute'));

        $this->expectException(\DomainException::class);
        $entry->synchronizePublication();
    }
}
