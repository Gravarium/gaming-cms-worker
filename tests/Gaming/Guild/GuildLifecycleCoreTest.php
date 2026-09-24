<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Guild;

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
}
