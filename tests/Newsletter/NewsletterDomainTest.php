<?php

declare(strict_types=1);

namespace App\Tests\Newsletter;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Entity\Newsletter\NewsletterSubscription;
use PHPUnit\Framework\TestCase;

final class NewsletterDomainTest extends TestCase
{
    public function testDoubleOptInUnsubscribeAndSuppressionAreFailClosed(): void
    {
        $subscription = (new NewsletterSubscription())->setEmail('member@example.test');
        $now = new \DateTimeImmutable('2026-09-24T10:00:00Z');
        $token = $subscription->issueConfirmation('account', 'v1', $now);

        self::assertSame(NewsletterSubscription::STATUS_PENDING, $subscription->getStatus());
        self::assertFalse($subscription->canReceive());

        try {
            $subscription->confirm('wrong-token', $now->modify('+1 minute'));
            self::fail('Wrong confirmation token must fail.');
        } catch (\DomainException) {
        }

        $subscription->confirm($token, $now->modify('+2 minutes'));
        self::assertTrue($subscription->canReceive());
        self::assertNotNull($subscription->getConfirmedAt());

        $unsubscribe = $subscription->issueUnsubscribeToken($now->modify('+3 minutes'));
        $subscription->unsubscribe($unsubscribe, $now->modify('+4 minutes'));
        self::assertSame(NewsletterSubscription::STATUS_UNSUBSCRIBED, $subscription->getStatus());
        self::assertFalse($subscription->canReceive());

        $subscription->suppress('hard_bounce', $now->modify('+5 minutes'));
        self::assertSame(NewsletterSubscription::STATUS_SUPPRESSED, $subscription->getStatus());
        $this->expectException(\DomainException::class);
        $subscription->issueConfirmation('account', 'v1', $now->modify('+6 minutes'));
    }

    public function testCampaignSchedulingRequiresFutureAndFinalCampaignIsImmutable(): void
    {
        $campaign = (new NewsletterCampaign())
            ->setTitle('Release')
            ->setSubject('Subject')
            ->setBodyText('Body');
        $now = new \DateTimeImmutable('2026-09-24T10:00:00Z');

        try {
            $campaign->schedule($now, $now);
            self::fail('Past/current schedule must fail.');
        } catch (\DomainException) {
        }

        $at = $now->modify('+1 hour');
        $campaign->schedule($at, $now);
        self::assertFalse($campaign->canDispatch($at->modify('-1 second')));
        self::assertTrue($campaign->canDispatch($at));
        $campaign->markSending($at);
        $campaign->markSent($at->modify('+1 minute'));
        self::assertTrue($campaign->isFinal());

        $this->expectException(\DomainException::class);
        $campaign->schedule($at->modify('+2 hours'), $at);
    }

    public function testDeliveryUsesBoundedExponentialRetryAndEventuallyFails(): void
    {
        $campaign = new NewsletterCampaign();
        $subscription = (new NewsletterSubscription())->setEmail('member@example.test');
        $delivery = new NewsletterDelivery($campaign, $subscription);
        $now = new \DateTimeImmutable('2026-09-24T10:00:00Z');

        $delivery->markFailure('transport_unavailable', $now);
        self::assertSame(NewsletterDelivery::STATUS_RETRY, $delivery->getStatus());
        self::assertEquals($now->modify('+5 minutes'), $delivery->getRetryAt());

        for ($attempt = 2; $attempt <= 5; ++$attempt) {
            $delivery->markFailure('transport_unavailable', $now->modify('+'.$attempt.' minutes'));
        }

        self::assertSame(5, $delivery->getAttempts());
        self::assertSame(NewsletterDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertNull($delivery->getRetryAt());
    }
}
