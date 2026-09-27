<?php

declare(strict_types=1);

namespace App\Notification\Preferences;

final readonly class NotificationPreferenceGate
{
    public function __construct(
        private NotificationPreferenceStore $preferences,
        private NotificationDeduplicator $deduplicator,
    ) {}

    public function claimImmediateDelivery(
        int $userId,
        string $topic,
        string $channel,
        string $dedupeKey,
        \DateTimeImmutable $now,
        int $ttlSeconds = 86400,
    ): bool {
        $preference = $this->preferences->forUser($userId);
        if (!$preference->allows($topic, $channel, $now)) {
            return false;
        }

        if ($channel === NotificationPreference::CHANNEL_EMAIL && !$preference->shouldSendImmediately($topic, $now)) {
            return false;
        }

        return $this->deduplicator->claim($userId, $dedupeKey, $now, $ttlSeconds);
    }
}
