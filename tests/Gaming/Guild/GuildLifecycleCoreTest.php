<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Guild;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Entity\Guild\GuildApplicationVote;
use App\Entity\Guild\GuildCharacterProfile;
use App\Entity\Guild\GuildMemberLifecycleEvent;
use App\Entity\Guild\GuildPrivateMemberNote;
use App\Entity\Guild\GuildRecruitmentCase;
use App\Entity\Guild\GuildRoleNeed;
use App\Gaming\Guild\GuildFeatureGate;
use App\Gaming\Guild\LocalGuildPolicy;
use App\Gaming\Guild\MemberLifecycleHistory;
use App\Gaming\Guild\RecruitmentPipeline;
use App\Gaming\Guild\RosterFilter;
use App\Gaming\Guild\RosterPrivacyPolicy;
use PHPUnit\Framework\TestCase;

final class GuildLifecycleCoreTest extends TestCase
{
    public function testRecruitmentRequiresReasonsAndSupportsTrialExpiryAndAppeal(): void
    {
        $pipeline = new RecruitmentPipeline();
        $pipeline->startReview();
        $pipeline->startTrial(new \DateTimeImmutable('+1 day'));
        self::assertSame(RecruitmentPipeline::TRIAL, $pipeline->status());

        $pipeline->expire(new \DateTimeImmutable('+2 days'));
        self::assertSame(RecruitmentPipeline::EXPIRED, $pipeline->status());
        $pipeline->appeal('I would like another review.');
        self::assertSame('I would like another review.', $pipeline->appealMessage());
    }

    public function testAcceptedDecisionStoresAuditReason(): void
    {
        $pipeline = new RecruitmentPipeline();
        $pipeline->startReview();
        $pipeline->decide(true, 'Officer vote passed.');

        self::assertSame(RecruitmentPipeline::ACCEPTED, $pipeline->status());
        self::assertSame('Officer vote passed.', $pipeline->decisionReason());
        self::assertNotNull($pipeline->decidedAt());
    }

    public function testCrossGuildAndPrivateFieldsFailClosed(): void
    {
        $policy = new RosterPrivacyPolicy();

        self::assertFalse($policy->canViewGuildField(1, 2, false, true));
        self::assertFalse($policy->canViewGuildField(1, 1, true, false));
        self::assertTrue($policy->canViewGuildField(1, 1, true, true));
        self::assertFalse($policy->canMutateGuildRecord(1, 2, true));
    }

    public function testDisabledGuildModuleAndUnknownContextFailClosed(): void
    {
        $gate = new GuildFeatureGate();
        self::assertFalse($gate->allows(false, 1, 1, true));
        self::assertFalse($gate->allows(true, 1, 2, true));
        self::assertFalse($gate->allows(true, 0, 1, true));
        self::assertTrue($gate->allows(true, 1, 1, true));

        $policy = new LocalGuildPolicy();
        self::assertFalse($policy->allows('', 5, 1, 1));
        self::assertFalse($policy->allows('member.view', 5, 1, 2));
        self::assertTrue($policy->allows('member.view', 5, 1, 1));
    }

    public function testLifecycleHistoryIsAppendOnlyEvidence(): void
    {
        $history = new MemberLifecycleHistory();
        $history->record('promote', 9, 'Promoted after trial.');
        $history->record('absence', 9, 'Approved leave.');

        self::assertCount(2, $history->events());
        self::assertSame('promote', $history->events()[0]['action']);
        self::assertSame('Approved leave.', $history->events()[1]['reason']);
    }

    public function testRosterFiltersRejectInvalidGameIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RosterFilter(0, 'tank');
    }

    public function testPersistentRosterAndRecruitmentObjectsKeepGuildBoundary(): void
    {
        [$guild, $otherGuild, $game] = $this->guilds();
        $member = (new GuildMember())->setGuild($guild)->setCharacterName('Main');
        $actor = $this->user('officer');
        $application = (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName('Applicant')
            ->setEmail('applicant@example.test')
            ->setCharacterName('ApplicantMain')
            ->setMessage(str_repeat('a', 30));

        $character = (new GuildCharacterProfile($guild, $member, $game, 'Alt'))
            ->setRole('heal')
            ->setCharacterClass('Priest')
            ->setMainCharacter(false);
        self::assertSame($guild, $character->getGuild());

        $need = (new GuildRoleNeed($guild, $game, 'heal', 'Priest'))->setDesiredCount(2);
        self::assertSame(2, $need->getDesiredCount());

        $vote = new GuildApplicationVote($application, $guild, $actor, 'approve', 'Strong application.');
        self::assertSame('approve', $vote->getDecision());

        $case = new GuildRecruitmentCase($application, $guild);
        $case->startReview();
        $case->startTrial(new \DateTimeImmutable('+1 day'));
        $case->decide(true, $actor, 'Trial passed.');
        self::assertSame('accepted', $case->getStatus());
        self::assertSame('Trial passed.', $case->getDecisionReason());

        $history = new GuildMemberLifecycleEvent($guild, $member, $actor, 'onboard', 'Trial completed.');
        self::assertSame('onboard', $history->getAction());

        $note = new GuildPrivateMemberNote($guild, $member, $actor, 'Officer-only context.');
        self::assertSame('Officer-only context.', $note->getNote());

        $this->expectException(\DomainException::class);
        new GuildPrivateMemberNote($otherGuild, $member, $actor, 'Must fail.');
    }

    public function testApplicationVoteRejectsCrossGuildApplication(): void
    {
        [$guild, $otherGuild] = $this->guilds();
        $actor = $this->user('vote-officer');
        $application = (new GuildApplication())
            ->setGuild($guild)
            ->setApplicantName('Applicant')
            ->setEmail('cross-guild@example.test')
            ->setCharacterName('CrossGuild')
            ->setMessage(str_repeat('b', 30));

        $this->expectException(\DomainException::class);
        new GuildApplicationVote($application, $otherGuild, $actor, 'approve', 'Must not cross guild boundaries.');
    }

    /** @return array{Guild, Guild, Game} */
    private function guilds(): array
    {
        $game = (new Game())->setName('Test game')->setSlug('test-game');
        $guild = (new Guild())->setGame($game)->setName('Guild A')->setSlug('guild-a')->setServerName('Server')->setDescription('A');
        $other = (new Guild())->setGame($game)->setName('Guild B')->setSlug('guild-b')->setServerName('Server')->setDescription('B');

        return [$guild, $other, $game];
    }

    private function user(string $name): User
    {
        return (new User())->setEmail($name.'@example.test')->setDisplayName($name)->verifyEmail();
    }
}
