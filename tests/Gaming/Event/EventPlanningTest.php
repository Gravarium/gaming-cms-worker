<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Event;

use App\Gaming\Event\AttendanceHistory;
use App\Gaming\Event\AttendancePlanner;
use App\Gaming\Event\CalendarExporter;
use App\Gaming\Event\EventOccurrence;
use App\Gaming\Event\EventVisibilityPolicy;
use App\Gaming\Event\RecurringEventSchedule;
use App\Gaming\Event\SignupDecision;
use PHPUnit\Framework\TestCase;

final class EventPlanningTest extends TestCase
{
    public function testRecurringEventsRemainStableAcrossDaylightSavingBoundary(): void
    {
        $schedule = new RecurringEventSchedule('Europe/Berlin', RecurringEventSchedule::WEEKLY, 3);
        $events = $schedule->expand(
            new \DateTimeImmutable('2026-10-18 20:00', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-10-18 22:00', new \DateTimeZone('Europe/Berlin')),
        );
        self::assertCount(3, $events);
        self::assertSame('18:00', $events[0]->startsAt->format('H:i'));
        self::assertSame('19:00', $events[2]->startsAt->format('H:i'));
    }

    public function testRoleCapacityWaitlistAndDeterministicPromotion(): void
    {
        $planner = new AttendancePlanner();
        self::assertSame(SignupDecision::CONFIRMED, $planner->decide('tank', ['tank' => 2], ['tank' => 1], 0)->status);
        $decision = $planner->decide('tank', ['tank' => 2], ['tank' => 2], 3);
        self::assertSame(SignupDecision::WAITLIST, $decision->status);
        self::assertSame(4, $decision->position);
        self::assertSame(7, $planner->nextPromotion('tank', [
            ['id' => 8, 'role' => 'tank', 'position' => 2],
            ['id' => 7, 'role' => 'tank', 'position' => 1],
            ['id' => 6, 'role' => 'heal', 'position' => 1],
        ]));
    }

    public function testVisibilityFailsClosedAndPreparationIsOfficerOnly(): void
    {
        $policy = new EventVisibilityPolicy();
        self::assertFalse($policy->canView('unknown', true, true));
        self::assertFalse($policy->canView(EventVisibilityPolicy::MEMBERS, false, false));
        self::assertTrue($policy->canView(EventVisibilityPolicy::MEMBERS, true, false));
        self::assertFalse($policy->canViewPreparation(false));
        self::assertTrue($policy->canViewPreparation(true));
    }

    public function testAttendanceEvidenceIsAppendOnlyAndChronological(): void
    {
        $history = new AttendanceHistory();
        $history->record('checked_in', new \DateTimeImmutable('2026-09-24T18:00:00Z'), 4);
        $history->record('attended', new \DateTimeImmutable('2026-09-24T20:00:00Z'), 4);
        self::assertCount(2, $history->entries());
        $this->expectException(\DomainException::class);
        $history->record('absent', new \DateTimeImmutable('2026-09-24T17:00:00Z'), 4);
    }

    public function testCalendarExportEscapesContentAndUsesUtc(): void
    {
        $calendar = (new CalendarExporter())->export(
            'raid-1@example.test',
            "Raid, progression\nsecret",
            new EventOccurrence(new \DateTimeImmutable('2026-09-24T18:00:00Z'), new \DateTimeImmutable('2026-09-24T20:00:00Z')),
        );
        self::assertStringContainsString('DTSTART:20260924T180000Z', $calendar);
        self::assertStringContainsString('SUMMARY:Raid\\, progression\\nsecret', $calendar);
    }
}
