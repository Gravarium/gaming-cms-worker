<?php

declare(strict_types=1);

namespace App\Notification\Preferences;

use Doctrine\DBAL\Connection;

final readonly class NotificationSubscriptionStore
{
    public function __construct(private Connection $connection) {}

    public function subscribe(int $userId, string $topic, \DateTimeImmutable $now): void
    {
        $topic = $this->topic($topic);
        if ($userId < 1) {
            throw new \InvalidArgumentException('A persisted user is required.');
        }

        $this->connection->executeStatement(
            'INSERT INTO notification_subscription (user_id, topic, created_at) VALUES (:user, :topic, :created) ON CONFLICT (user_id, topic) DO NOTHING',
            ['user' => $userId, 'topic' => $topic, 'created' => $now->format('Y-m-d H:i:s')],
        );
    }

    public function unsubscribe(int $userId, string $topic): void
    {
        $topic = $this->topic($topic);
        $this->connection->executeStatement(
            'DELETE FROM notification_subscription WHERE user_id = :user AND topic = :topic',
            ['user' => $userId, 'topic' => $topic],
        );
    }

    public function isSubscribed(int $userId, string $topic): bool
    {
        $topic = $this->topic($topic);

        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM notification_subscription WHERE user_id = :user AND topic = :topic',
            ['user' => $userId, 'topic' => $topic],
        );
    }

    /** @return list<string> */
    public function topicsForUser(int $userId): array
    {
        return array_map(
            static fn (mixed $topic): string => (string) $topic,
            $this->connection->fetchFirstColumn(
                'SELECT topic FROM notification_subscription WHERE user_id = :user ORDER BY topic ASC',
                ['user' => $userId],
            ),
        );
    }

    private function topic(string $topic): string
    {
        $topic = strtolower(trim($topic));
        if (preg_match('/^[a-z0-9][a-z0-9:._-]{0,127}$/D', $topic) !== 1) {
            throw new \InvalidArgumentException('Invalid notification subscription topic.');
        }

        return $topic;
    }
}
