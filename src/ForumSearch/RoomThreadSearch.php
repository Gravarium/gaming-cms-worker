<?php

declare(strict_types=1);

namespace App\ForumSearch;

use Doctrine\DBAL\Connection;

final readonly class RoomThreadSearch
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGE = 1000;

    public function __construct(private Connection $connection)
    {
    }

    /** @return array{total:int, threads:list<array<string, mixed>>} */
    public function search(int $roomId, string $term, int $page): array
    {
        if ($roomId < 1 || $page < 1 || $page > self::MAX_PAGE
            || !mb_check_encoding($term, 'UTF-8') || mb_strlen($term) < 2 || mb_strlen($term) > 100) {
            throw new \InvalidArgumentException('The forum search request is invalid.');
        }

        // ESCAPE makes wildcard characters in user input literal on SQLite and PostgreSQL.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term, 'UTF-8')).'%';
        $where = "t.room_id = :room_id AND (LOWER(t.title) LIKE :pattern ESCAPE '!' OR EXISTS (
            SELECT 1 FROM forum_post p WHERE p.thread_id = t.id AND LOWER(p.body) LIKE :pattern ESCAPE '!'
        ))";
        $parameters = ['room_id' => $roomId, 'pattern' => $pattern];
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_thread t WHERE '.$where, $parameters);
        $threads = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.title, t.state, t.solved_post_id, t.updated_at,
                    (SELECT COUNT(*) FROM forum_post p WHERE p.thread_id = t.id) AS post_count
             FROM forum_thread t WHERE '.$where.' ORDER BY t.updated_at DESC, t.id DESC
             LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => self::PAGE_SIZE, 'offset' => ($page - 1) * self::PAGE_SIZE],
        );

        return ['total' => $total, 'threads' => $threads];
    }
}
