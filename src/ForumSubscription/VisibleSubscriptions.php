<?php

declare(strict_types=1);

namespace App\ForumSubscription;

use App\ForumWorkflow\ForumWorkflowGateway;
use Doctrine\DBAL\Connection;

final readonly class VisibleSubscriptions
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;

    public function __construct(
        private Connection $connection,
        private ForumWorkflowGateway $forum,
    ) {
    }

    /**
     * @param list<int> $guildIds
     * @return array{threads: list<array<string, mixed>>, total: int}
     */
    public function page(int $userId, array $guildIds, bool $isModerator, int $page): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Invalid subscription page.');
        }

        $rows = $this->connection->executeQuery(
            'SELECT t.id, t.room_id, t.title, t.state, t.updated_at
             FROM forum_subscription s
             INNER JOIN forum_thread t ON t.id = s.thread_id
             WHERE s.user_id = :user_id
             ORDER BY t.updated_at DESC, t.id DESC',
            ['user_id' => $userId],
        );

        $visibleRooms = [];
        $threads = [];
        $total = 0;
        $start = ($page - 1) * self::PAGE_SIZE;
        foreach ($rows->iterateAssociative() as $thread) {
            $roomId = (int) $thread['room_id'];
            if (!array_key_exists($roomId, $visibleRooms)) {
                $visibleRooms[$roomId] = $this->forum->visibleRoom($roomId, $userId, $guildIds, $isModerator, true);
            }
            if ($visibleRooms[$roomId] === null) {
                continue;
            }

            if ($total >= $start && count($threads) < self::PAGE_SIZE) {
                $threads[] = $thread;
            }
            ++$total;
        }

        return ['threads' => $threads, 'total' => $total];
    }
}
