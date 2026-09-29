<?php

declare(strict_types=1);

namespace App\Tests\Forum;

use App\Forum\ForumAttachmentReference;
use App\Forum\ForumPost;
use App\Forum\ForumRoomPolicy;
use App\Forum\ForumSubscriptions;
use App\Forum\ForumThread;
use PHPUnit\Framework\TestCase;

final class ForumDomainTest extends TestCase
{
    public function testGuildRoomIsObjectScopedAndFailsClosed(): void
    {
        $policy = new ForumRoomPolicy();
        self::assertTrue($policy->canView('guild', 4, 4, true, false, true));
        self::assertFalse($policy->canView('guild', 4, 5, true, false, true));
        self::assertFalse($policy->canView('unknown', 4, 4, true, true, true));
        self::assertFalse($policy->canView('public', null, null, false, false, false));
    }

    public function testThreadConcurrencySolvedAnswerAndModerationAudit(): void
    {
        $thread = new ForumThread(7, 'How do I configure this build?');
        $thread->markSolved(12, 7, 0);
        self::assertSame(12, $thread->solvedPostId());
        self::assertSame(1, $thread->version());
        $thread->transition('locked', 9, 'Resolved and duplicate replies stopped', 1, new \DateTimeImmutable());
        self::assertSame('locked', $thread->state());
        self::assertCount(1, $thread->moderationHistory());
    }

    public function testStaleThreadMutationIsRejected(): void
    {
        $thread = new ForumThread(7, 'Question');
        $thread->transition('locked', 9, 'Moderation', 0, new \DateTimeImmutable());
        $this->expectException(\DomainException::class);
        $thread->transition('open', 9, 'Stale reopen', 0, new \DateTimeImmutable());
    }

    public function testPostsBoundQuotesAndMentions(): void
    {
        $post = new ForumPost(2, 7, 'Safe plain forum body', 10, [8, 9]);
        self::assertSame(10, $post->quotedPostId);
        self::assertSame([8, 9], $post->mentionedUserIds);
    }

    public function testSubscriptionsExcludeActor(): void
    {
        $subscriptions = new ForumSubscriptions();
        $subscriptions->subscribe(7);
        $subscriptions->subscribe(8);
        self::assertSame([8], $subscriptions->recipientsExcept(7));
    }

    public function testAttachmentsRejectFreeUrls(): void
    {
        new ForumAttachmentReference('media', 12, 7);
        $this->expectException(\DomainException::class);
        ForumAttachmentReference::fromUrl('https://example.test/file.svg');
    }
}
