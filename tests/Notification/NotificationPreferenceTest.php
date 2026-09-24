<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Preferences\NotificationPreference;
use PHPUnit\Framework\TestCase;

final class NotificationPreferenceTest extends TestCase
{
    public function testQuietHoursHandleOvernightRangesInConfiguredTimezone(): void
    {
        $preference = new NotificationPreference(
            true,
            true,
            true,
            true,
            NotificationPreference::DIGEST_IMMEDIATE,
            '22:00',
            '07:00',
            'Europe/Berlin',
        );

        self::assertTrue($preference->isQuietAt(new \DateTimeImmutable('2026-01-10T23:30:00+01:00')));
        self::assertTrue($preference->isQuietAt(new \DateTimeImmutable('2026-01-11T06:59:00+01:00')));
        self::assertFalse($preference->isQuietAt(new \DateTimeImmutable('2026-01-11T07:00:00+01:00')));
        self::assertFalse($preference->allows(NotificationPreference::TOPIC_MENTION, NotificationPreference::CHANNEL_EMAIL, new \DateTimeImmutable('2026-01-10T23:30:00+01:00')));
        self::assertTrue($preference->allows(NotificationPreference::TOPIC_MENTION, NotificationPreference::CHANNEL_IN_APP, new \DateTimeImmutable('2026-01-10T23:30:00+01:00')));
    }

    public function testDigestCanDeferEmailWithoutDisablingInAppNotifications(): void
    {
        $preference = new NotificationPreference(
            true,
            true,
            true,
            true,
            NotificationPreference::DIGEST_DAILY,
        );
        $now = new \DateTimeImmutable('2026-09-24T12:00:00Z');

        self::assertTrue($preference->allows(NotificationPreference::TOPIC_SUBSCRIPTION, NotificationPreference::CHANNEL_EMAIL, $now));
        self::assertFalse($preference->shouldSendImmediately(NotificationPreference::TOPIC_SUBSCRIPTION, $now));
        self::assertTrue($preference->allows(NotificationPreference::TOPIC_SUBSCRIPTION, NotificationPreference::CHANNEL_IN_APP, $now));
    }

    public function testInvalidQuietHoursAndTimezoneFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NotificationPreference(true, true, true, true, NotificationPreference::DIGEST_IMMEDIATE, '25:00', '07:00', 'UTC');
    }

    public function testUnknownTimezoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NotificationPreference(true, true, true, true, NotificationPreference::DIGEST_IMMEDIATE, null, null, 'Not/AZone');
    }
}
