<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\GuildRank;
use App\Entity\GuildTeam;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

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


    public function testApplicationDecisionIsFinal(): void
    {
        $application = new GuildApplication();
        $application->accept();

        self::assertSame(GuildApplication::STATUS_ACCEPTED, $application->getStatus());
        self::assertFalse($application->isOpen());

        $this->expectException(\DomainException::class);
        $application->reject();
    }

    public function testRejectedApplicationCannotBeAcceptedLater(): void
    {
        $application = new GuildApplication();
        $application->reject();

        $this->expectException(\DomainException::class);
        $application->accept();
    }

    public function testMemberRejectsRankFromAnotherGuild(): void
    {
        $guild = new Guild();
        $otherGuild = new Guild();
        $rank = (new GuildRank())->setGuild($otherGuild)->setName('Officer');
        $member = (new GuildMember())->setGuild($guild);

        $this->expectException(\DomainException::class);
        $member->setRank($rank);
    }

    public function testTeamRejectsMembersFromAnotherGuild(): void
    {
        $guild = new Guild();
        $otherGuild = new Guild();
        $member = (new GuildMember())->setGuild($otherGuild);
        $team = (new GuildTeam())->setGuild($guild);

        $this->expectException(\DomainException::class);
        $team->addMember($member);
    }

    public function testEventRejectsTeamFromAnotherGuild(): void
    {
        $guild = new Guild();
        $otherGuild = new Guild();
        $team = (new GuildTeam())->setGuild($otherGuild);
        $event = (new GuildEvent())->setGuild($guild);

        $this->expectException(\DomainException::class);
        $event->setTeam($team);
    }

    public function testEventValidationRejectsInvalidStateAndEndBeforeStart(): void
    {
        $start = new \DateTimeImmutable('+2 days');
        $event = (new GuildEvent())
            ->setTitle('Progress')
            ->setStartsAt($start)
            ->setEndsAt($start->modify('-1 hour'))
            ->setType('invalid')
            ->setStatus('invalid');

        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($event);
        $paths = [];
        foreach ($violations as $violation) { $paths[] = $violation->getPropertyPath(); }

        self::assertContains('endsAt', $paths);
        self::assertContains('type', $paths);
        self::assertContains('status', $paths);
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
