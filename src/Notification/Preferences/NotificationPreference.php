<?php

declare(strict_types=1);

namespace App\Notification\Preferences;

final readonly class NotificationPreference
{
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL = 'email';

    public const TOPIC_MENTION = 'mention';
    public const TOPIC_SUBSCRIPTION = 'subscription';

    public const DIGEST_IMMEDIATE = 'immediate';
    public const DIGEST_DAILY = 'daily';
    public const DIGEST_WEEKLY = 'weekly';
    public const DIGEST_OFF = 'off';

    public function __construct(
        public bool $inAppEnabled = true,
        public bool $emailEnabled = true,
        public bool $mentionsEnabled = true,
        public bool $subscriptionsEnabled = true,
        public string $digestFrequency = self::DIGEST_IMMEDIATE,
        public ?string $quietHoursStart = null,
        public ?string $quietHoursEnd = null,
        public string $timezone = 'UTC',
    ) {
        if (!in_array($this->digestFrequency, [self::DIGEST_IMMEDIATE, self::DIGEST_DAILY, self::DIGEST_WEEKLY, self::DIGEST_OFF], true)) {
            throw new \InvalidArgumentException('Unknown notification digest frequency.');
        }

        if (($this->quietHoursStart === null) !== ($this->quietHoursEnd === null)) {
            throw new \InvalidArgumentException('Quiet hours require both a start and an end time.');
        }

        if ($this->quietHoursStart !== null) {
            $this->assertClock($this->quietHoursStart);
            $this->assertClock((string) $this->quietHoursEnd);
            if ($this->quietHoursStart === $this->quietHoursEnd) {
                throw new \InvalidArgumentException('Quiet hours start and end must differ.');
            }
        }

        try {
            new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Unknown notification timezone.');
        }
    }

    public function allows(string $topic, string $channel, \DateTimeImmutable $now): bool
    {
        if (!in_array($topic, [self::TOPIC_MENTION, self::TOPIC_SUBSCRIPTION], true)) {
            throw new \InvalidArgumentException('Unknown notification topic.');
        }
        if (!in_array($channel, [self::CHANNEL_IN_APP, self::CHANNEL_EMAIL], true)) {
            throw new \InvalidArgumentException('Unknown notification channel.');
        }

        $topicEnabled = $topic === self::TOPIC_MENTION ? $this->mentionsEnabled : $this->subscriptionsEnabled;
        if (!$topicEnabled) {
            return false;
        }

        if ($channel === self::CHANNEL_IN_APP) {
            return $this->inAppEnabled;
        }

        if (!$this->emailEnabled || $this->digestFrequency === self::DIGEST_OFF) {
            return false;
        }

        return !$this->isQuietAt($now);
    }

    public function shouldSendImmediately(string $topic, \DateTimeImmutable $now): bool
    {
        return $this->digestFrequency === self::DIGEST_IMMEDIATE
            && $this->allows($topic, self::CHANNEL_EMAIL, $now);
    }

    public function isQuietAt(\DateTimeImmutable $now): bool
    {
        if ($this->quietHoursStart === null || $this->quietHoursEnd === null) {
            return false;
        }

        $local = $now->setTimezone(new \DateTimeZone($this->timezone));
        $minutes = ((int) $local->format('H') * 60) + (int) $local->format('i');
        $start = $this->clockMinutes($this->quietHoursStart);
        $end = $this->clockMinutes($this->quietHoursEnd);

        if ($start < $end) {
            return $minutes >= $start && $minutes < $end;
        }

        return $minutes >= $start || $minutes < $end;
    }

    private function assertClock(string $value): void
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Quiet hours must use HH:MM.');
        }
    }

    private function clockMinutes(string $value): int
    {
        return ((int) substr($value, 0, 2) * 60) + (int) substr($value, 3, 2);
    }
}
