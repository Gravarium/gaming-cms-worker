<?php

declare(strict_types=1);

namespace App\Notification\Preferences;

use Doctrine\DBAL\Connection;

final readonly class NotificationDeduplicator
{
    public function __construct(private Connection $connection) {}

    public function claim(int $userId, string $key, \DateTimeImmutable $now, int $ttlSeconds = 86400): bool
    {
        $key = trim($key);
        if ($userId < 1 || $key === '' || strlen($key) > 500 || $ttlSeconds < 1 || $ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Invalid notification deduplication claim.');
        }

        $expires = $now->modify('+'.$ttlSeconds.' seconds');
        $affected = $this->connection->executeStatement(
            "INSERT INTO notification_deduplication (user_id, dedupe_hash, expires_at)
             VALUES (:user, :hash, :expires)
             ON CONFLICT (user_id, dedupe_hash) DO UPDATE
             SET expires_at = EXCLUDED.expires_at
             WHERE notification_deduplication.expires_at <= :now",
            [
                'user' => $userId,
                'hash' => hash('sha256', $key),
                'expires' => $expires->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return $affected === 1;
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM notification_deduplication WHERE expires_at <= :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
