<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildApplication;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\GuildTeam;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class GuildOperationsPlatformTest extends TestCase
{
    public function testTeamKeepsLeaderInMemberCollectionWithoutDuplicates(): void
    {
        $leader = (new GuildMember())->setCharacterName('Raidlead');
        $team = (new GuildTeam())->setName('Progress')->setLeader($leader)->addMember($leader)->addMember($leader);

        self::assertSame('Progress', $team->getName());
        self::assertSame($leader, $team->getLeader());
        self::assertCount(1, $team->getMembers());
    }

    public function testApplicationReviewCanBeAssignedAndReleased(): void
    {
        $reviewer = new User();
        $application = (new GuildApplication())->assignTo($reviewer)->setInternalNotes('Rückfrage im Discord');

        self::assertSame(GuildApplication::STATUS_REVIEWING, $application->getStatus());
        self::assertSame($reviewer, $application->getAssignedTo());
        self::assertSame('Rückfrage im Discord', $application->getInternalNotes());

        $application->assignTo(null);
        self::assertSame(GuildApplication::STATUS_PENDING, $application->getStatus());
        self::assertNull($application->getAssignedTo());
    }

    public function testAttendanceAcceptsOnlyKnownStates(): void
    {
        $signup = new GuildEventSignup();
        $signup->markAttendance(GuildEventSignup::ATTENDANCE_PRESENT, null);
        self::assertSame(GuildEventSignup::ATTENDANCE_PRESENT, $signup->getAttendance());
        self::assertNotNull($signup->getAttendanceCheckedAt());

        $signup->markAttendance(GuildEventSignup::ATTENDANCE_UNKNOWN, null);
        self::assertNull($signup->getAttendanceCheckedAt());

        $this->expectException(\InvalidArgumentException::class);
        $signup->markAttendance('late', null);
    }
}
