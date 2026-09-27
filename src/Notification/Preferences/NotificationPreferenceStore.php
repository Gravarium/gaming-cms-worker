<?php

declare(strict_types=1);

namespace App\Notification\Preferences;

use Doctrine\DBAL\Connection;

final readonly class NotificationPreferenceStore
{
    public function __construct(private Connection $connection) {}

    public function forUser(int $userId): NotificationPreference
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('A persisted user is required.');
        }

        $row = $this->connection->fetchAssociative(
            'SELECT in_app_enabled, email_enabled, mentions_enabled, subscriptions_enabled, digest_frequency, quiet_hours_start, quiet_hours_end, timezone FROM notification_preference WHERE user_id = :user',
            ['user' => $userId],
        );

        if ($row === false) {
            return new NotificationPreference();
        }

        return new NotificationPreference(
            $this->bool($row['in_app_enabled']),
            $this->bool($row['email_enabled']),
            $this->bool($row['mentions_enabled']),
            $this->bool($row['subscriptions_enabled']),
            (string) $row['digest_frequency'],
            $row['quiet_hours_start'] !== null ? (string) $row['quiet_hours_start'] : null,
            $row['quiet_hours_end'] !== null ? (string) $row['quiet_hours_end'] : null,
            (string) $row['timezone'],
        );
    }

    public function save(int $userId, NotificationPreference $preference, \DateTimeImmutable $now): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('A persisted user is required.');
        }

        $this->connection->executeStatement(
            "INSERT INTO notification_preference (user_id, in_app_enabled, email_enabled, mentions_enabled, subscriptions_enabled, digest_frequency, quiet_hours_start, quiet_hours_end, timezone, updated_at)
             VALUES (:user, :in_app, :email, :mentions, :subscriptions, :digest, :quiet_start, :quiet_end, :timezone, :updated)
             ON CONFLICT (user_id) DO UPDATE SET
                in_app_enabled = EXCLUDED.in_app_enabled,
                email_enabled = EXCLUDED.email_enabled,
                mentions_enabled = EXCLUDED.mentions_enabled,
                subscriptions_enabled = EXCLUDED.subscriptions_enabled,
                digest_frequency = EXCLUDED.digest_frequency,
                quiet_hours_start = EXCLUDED.quiet_hours_start,
                quiet_hours_end = EXCLUDED.quiet_hours_end,
                timezone = EXCLUDED.timezone,
                updated_at = EXCLUDED.updated_at",
            [
                'user' => $userId,
                'in_app' => $preference->inAppEnabled ? 'true' : 'false',
                'email' => $preference->emailEnabled ? 'true' : 'false',
                'mentions' => $preference->mentionsEnabled ? 'true' : 'false',
                'subscriptions' => $preference->subscriptionsEnabled ? 'true' : 'false',
                'digest' => $preference->digestFrequency,
                'quiet_start' => $preference->quietHoursStart,
                'quiet_end' => $preference->quietHoursEnd,
                'timezone' => $preference->timezone,
                'updated' => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
