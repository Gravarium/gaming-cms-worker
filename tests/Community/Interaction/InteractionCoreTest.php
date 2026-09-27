<?php

declare(strict_types=1);

namespace App\Tests\Community\Interaction;

use App\Community\Interaction\CommentRecord;
use App\Community\Interaction\InteractionActor;
use App\Community\Interaction\InteractionAuthorization;
use App\Community\Interaction\InteractionTargetContext;
use App\Community\Interaction\InteractionTargetRegistry;
use App\Community\Interaction\ModerationDecision;
use App\Community\Interaction\ReactionPolicy;
use App\Community\Interaction\ReportRecord;
use App\Entity\Community\CommunityComment;
use App\Entity\Community\CommunityModerationDecision;
use App\Entity\Community\CommunityReaction;
use App\Entity\Community\CommunityReport;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class InteractionCoreTest extends TestCase
{
    public function testVisibilityOwnershipModeratorAndDisabledModuleFailClosed(): void
    {
        $policy = new InteractionAuthorization();
        $owner = new InteractionActor(7);
        $other = new InteractionActor(8);
        $moderator = new InteractionActor(9, true);

        $private = new InteractionTargetContext('content', 10, true, false, 7);
        self::assertTrue($policy->canView($private, $owner));
        self::assertFalse($policy->canView($private, $other));
        self::assertTrue($policy->canView($private, $moderator));
        self::assertFalse($policy->canView(null, $moderator));
        self::assertFalse($policy->canView(new InteractionTargetContext('content', 10, false, true, 7), $owner));
        self::assertFalse($policy->canInteract($private, new InteractionActor(null)));
    }

    public function testUnknownRegistryTargetFailsClosed(): void
    {
        $registry = new InteractionTargetRegistry();
        self::assertFalse($registry->supports('video'));
        self::assertNull($registry->resolve('video', 1));
        self::assertNull($registry->resolve('content', 0));
    }

    public function testRepliesAreTargetBoundAndDepthIsBounded(): void
    {
        $root = new CommentRecord('content', 4, 1, 'root');
        $one = new CommentRecord('content', 4, 2, 'one', $root);
        $two = new CommentRecord('content', 4, 3, 'two', $one);
        $three = new CommentRecord('content', 4, 4, 'three', $two);
        $four = new CommentRecord('content', 4, 5, 'four', $three);
        self::assertSame(4, $four->depth());

        $this->expectException(\DomainException::class);
        new CommentRecord('content', 4, 6, 'too deep', $four);
    }

    public function testReplyCannotCrossTargetBoundary(): void
    {
        $root = new CommentRecord('content', 4, 1, 'root');

        $this->expectException(\InvalidArgumentException::class);
        new CommentRecord('content', 5, 2, 'wrong target', $root);
    }

    public function testSoftDeleteAndRestoreRetainAuditReasonUntilRestore(): void
    {
        $comment = new CommentRecord('content', 4, 1, 'hello');
        $comment->softDelete(9, 'moderation');
        self::assertTrue($comment->isDeleted());
        self::assertSame('moderation', $comment->deletionReason());
        self::assertNotNull($comment->deletedAt());

        $comment->restore(9);
        self::assertFalse($comment->isDeleted());
        self::assertNull($comment->deletionReason());
    }

    public function testReactionSetIsExplicitAndBounded(): void
    {
        $policy = new ReactionPolicy();
        $policy->assertCanAdd([], 'like');
        $policy->assertCanAdd(['like', 'helpful'], 'love');

        $this->expectException(\DomainException::class);
        $policy->assertCanAdd(['like', 'helpful', 'love'], 'wow');
    }

    public function testReportQueueRequiresReviewBeforeDecision(): void
    {
        $report = new ReportRecord('comment', 12, 3, 'abuse', 'details');
        self::assertSame(ReportRecord::STATUS_OPEN, $report->status());
        $report->startReview();
        $report->decide(true, 9, 'Confirmed by moderation evidence.');
        self::assertSame(ReportRecord::STATUS_RESOLVED, $report->status());
        self::assertSame('Confirmed by moderation evidence.', $report->decisionReason());
    }

    public function testModerationDecisionRequiresKnownActionAndReason(): void
    {
        $decision = new ModerationDecision('comment', 12, 9, 'hide', 'Policy violation');
        self::assertSame('hide', $decision->action);

        $this->expectException(\InvalidArgumentException::class);
        new ModerationDecision('comment', 12, 9, 'delete_forever', 'not allowed');
    }

    public function testPersistentCommunityModelsPreserveLifecycleAndAuditEvidence(): void
    {
        $author = $this->user('author');
        $moderator = $this->user('moderator');
        $root = new CommunityComment('content', 44, $author, 'Root comment');
        $reply = new CommunityComment('content', 44, $author, 'Reply', $root);
        self::assertSame(1, $reply->depth());

        $reaction = new CommunityReaction($root, $author, 'like');
        self::assertSame('like', $reaction->getReaction());

        $report = new CommunityReport($root, $author, 'abuse', 'Evidence');
        $report->startReview();
        $report->decide(true, $moderator, 'Confirmed.');
        self::assertSame(ReportRecord::STATUS_RESOLVED, $report->getStatus());

        $root->softDelete($moderator, 'Confirmed report');
        self::assertTrue($root->isDeleted());
        self::assertSame('Confirmed report', $root->getDeletionReason());
        $root->restore();
        self::assertFalse($root->isDeleted());

        $audit = new CommunityModerationDecision('comment', 44, $moderator, 'hide', 'Confirmed report');
        self::assertSame('hide', $audit->getAction());
        self::assertSame('Confirmed report', $audit->getReason());
    }

    private function user(string $name): User
    {
        return (new User())
            ->setEmail($name.'@example.test')
            ->setDisplayName($name)
            ->verifyEmail();
    }
}
