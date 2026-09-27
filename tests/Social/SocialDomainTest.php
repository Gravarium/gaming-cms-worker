<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Entity\Social\SocialAttachment;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialMessage;
use App\Entity\Social\SocialPrivacySettings;
use App\Entity\Social\SocialRelationship;
use App\Entity\Social\SocialReport;
use App\Entity\User;
use App\Social\SocialRateLimitPolicy;
use App\Social\SocialRetentionPolicy;
use PHPUnit\Framework\TestCase;

final class SocialDomainTest extends TestCase
{
    public function testConversationMembershipAndMessageLifecycleAreBounded(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $conversation = new SocialConversation($owner, SocialConversation::TYPE_GROUP, 'Raid group');
        $ownerParticipant = new SocialConversationParticipant($conversation, $owner, SocialConversationParticipant::ROLE_OWNER);
        $memberParticipant = new SocialConversationParticipant($conversation, $member);
        $message = new SocialMessage($conversation, $member, 'Hello team');

        self::assertTrue($ownerParticipant->isOwner());
        self::assertTrue($memberParticipant->isActive());
        self::assertSame('Hello team', $message->getBody());
        $message->edit('Updated', new \DateTimeImmutable());
        $message->softDelete($owner, 'moderation', new \DateTimeImmutable());
        self::assertTrue($message->isDeleted());
        self::assertSame('[Message removed]', $message->getBody());

        $memberParticipant->leave(new \DateTimeImmutable());
        self::assertFalse($memberParticipant->isActive());
        $this->expectException(\DomainException::class);
        $memberParticipant->markRead(new \DateTimeImmutable());
    }

    public function testGroupAndMessageLimitsFailClosed(): void
    {
        $policy = new SocialRateLimitPolicy();
        $policy->assertGroupSize(2);
        $policy->assertMessage('safe message');

        $this->expectException(\InvalidArgumentException::class);
        $policy->assertMessage(str_repeat('x', SocialRateLimitPolicy::MAX_MESSAGE_LENGTH + 1));
    }

    public function testPrivacyStateMachineRejectsUnknownValues(): void
    {
        $settings = new SocialPrivacySettings($this->user('privacy'));
        $settings->setMessagePolicy(SocialPrivacySettings::MESSAGE_RELATIONSHIPS);
        $settings->setRelationshipPolicy(SocialPrivacySettings::RELATIONSHIPS_NOBODY);
        self::assertSame(SocialPrivacySettings::MESSAGE_RELATIONSHIPS, $settings->getMessagePolicy());

        $this->expectException(\InvalidArgumentException::class);
        $settings->setMessagePolicy('public-to-everyone');
    }

    public function testRelationshipTransitionsRequirePendingState(): void
    {
        $relationship = new SocialRelationship($this->user('requester'), $this->user('recipient'), SocialRelationship::TYPE_FRIEND);
        $relationship->accept(new \DateTimeImmutable());
        self::assertTrue($relationship->isAccepted());

        $this->expectException(\DomainException::class);
        $relationship->reject(new \DateTimeImmutable());
    }

    public function testReportRequiresReviewAndProducesDecisionEvidence(): void
    {
        $author = $this->user('author');
        $reporter = $this->user('reporter');
        $conversation = new SocialConversation($author);
        $message = new SocialMessage($conversation, $author, 'Report me');
        $report = new SocialReport($message, $reporter, 'abuse', 'Unsafe content');
        $report->startReview();
        $report->decide(true, $this->user('moderator')->setAdmin(true), 'Confirmed by moderation evidence.', new \DateTimeImmutable());

        self::assertSame(SocialReport::STATUS_UPHELD, $report->getStatus());
        self::assertSame('Confirmed by moderation evidence.', $report->getDecisionReason());
    }

    public function testAttachmentNeverAcceptsRemoteOrExecutableLocations(): void
    {
        $author = $this->user('attachment');
        $conversation = new SocialConversation($author);
        $message = new SocialMessage($conversation, $author, 'With file');

        $attachment = new SocialAttachment($message, $author, 'var/share/social/file.pdf', 'file.pdf', 'application/pdf', 42);
        self::assertTrue($attachment->isAccessible());
        $attachment->revoke(new \DateTimeImmutable());
        self::assertFalse($attachment->isAccessible());

        $this->expectException(\InvalidArgumentException::class);
        new SocialAttachment($message, $author, 'https://example.test/file.pdf', 'file.pdf', 'application/pdf', 42);
    }

    public function testRetentionWindowIsExplicitAndBounded(): void
    {
        $policy = new SocialRetentionPolicy();
        $author = $this->user('retention');
        $conversation = new SocialConversation($author, SocialConversation::TYPE_DIRECT, null, 30);
        $message = new SocialMessage($conversation, $author, 'Old message');

        self::assertTrue($policy->isExpired($message, 30, new \DateTimeImmutable('+31 days')));
        self::assertFalse($policy->isExpired($message, 30, new \DateTimeImmutable('+29 days')));
    }

    private function user(string $name): User
    {
        return (new User())
            ->setEmail($name.'-'.bin2hex(random_bytes(3)).'@example.test')
            ->setDisplayName($name)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
    }
}
