<?php

declare(strict_types=1);

namespace App\ForumWorkflow;

use App\Forum\ForumPost;
use App\Forum\ForumRoomPolicy;
use App\Forum\ForumThread;
use Doctrine\DBAL\Connection;

final readonly class ForumWorkflowGateway
{
    public function __construct(
        private Connection $connection,
        private ForumRoomPolicy $roomPolicy,
    ) {
    }

    /** @return list<int> */
    public function guildIdsForUser(int $userId): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT guild_id FROM guild_member WHERE user_id = :user_id AND active = TRUE ORDER BY guild_id, id',
            ['user_id' => $userId],
        );

        return array_values(array_unique(array_map(static fn (mixed $value): int => (int) $value, $values)));
    }

    /**
     * @param list<int> $guildIds
     * @return list<array<string, mixed>>
     */
    public function visibleRooms(
        ?int $userId,
        array $guildIds,
        bool $isModerator,
        bool $moduleEnabled,
        int $limit = 20,
        int $offset = 0,
    ): array {
        if (!$moduleEnabled) {
            return [];
        }

        [$where, $parameters] = $this->visibilityWhere($userId, $guildIds, $isModerator);
        $parameters['limit'] = max(1, min(100, $limit));
        $parameters['offset'] = max(0, $offset);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.guild_id, r.title, r.visibility, COUNT(DISTINCT t.id) AS thread_count
             FROM forum_room r
             LEFT JOIN forum_thread t ON t.room_id = r.id
             WHERE '.$where.'
             GROUP BY r.id
             ORDER BY LOWER(r.title), r.id
             LIMIT :limit OFFSET :offset',
            $parameters,
        );

        return array_values(array_filter(
            $rows,
            fn (array $room): bool => $this->mayViewRoom($room, $userId, $guildIds, $isModerator, true),
        ));
    }

    /** @param list<int> $guildIds */
    public function visibleRoomCount(?int $userId, array $guildIds, bool $isModerator, bool $moduleEnabled): int
    {
        if (!$moduleEnabled) {
            return 0;
        }

        [$where, $parameters] = $this->visibilityWhere($userId, $guildIds, $isModerator);
        $rooms = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.guild_id, r.visibility FROM forum_room r WHERE '.$where,
            $parameters,
        );

        return count(array_filter(
            $rooms,
            fn (array $room): bool => $this->mayViewRoom($room, $userId, $guildIds, $isModerator, true),
        ));
    }

    /**
     * @param list<int> $guildIds
     * @return array<string, mixed>|null
     */
    public function visibleRoom(
        int $roomId,
        ?int $userId,
        array $guildIds,
        bool $isModerator,
        bool $moduleEnabled,
    ): ?array {
        if (!$moduleEnabled) {
            return null;
        }

        $room = $this->connection->fetchAssociative(
            'SELECT id, guild_id, title, visibility FROM forum_room WHERE id = :id',
            ['id' => $roomId],
        );

        if ($room === false || !$this->mayViewRoom($room, $userId, $guildIds, $isModerator, true)) {
            return null;
        }

        return $room;
    }

    /** @return list<array<string, mixed>> */
    public function threadsForRoom(int $roomId, int $limit = 20, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT t.id, t.room_id, t.author_id, t.solved_post_id, t.title, t.state, t.version, t.updated_at,
                    (SELECT COUNT(*) FROM forum_post p WHERE p.thread_id = t.id) AS post_count
             FROM forum_thread t
             WHERE t.room_id = :room_id
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT :limit OFFSET :offset',
            ['room_id' => $roomId, 'limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)],
        );
    }

    public function threadCountForRoom(int $roomId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM forum_thread WHERE room_id = :room_id',
            ['room_id' => $roomId],
        );
    }

    /**
     * @param list<int> $guildIds
     * @return array<string, mixed>|null
     */
    public function visibleThread(
        int $threadId,
        ?int $userId,
        array $guildIds,
        bool $isModerator,
        bool $moduleEnabled,
    ): ?array {
        if (!$moduleEnabled) {
            return null;
        }

        $thread = $this->connection->fetchAssociative(
            'SELECT t.id, t.room_id, t.author_id, t.solved_post_id, t.title, t.state, t.version, t.created_at, t.updated_at,
                    r.title AS room_title, r.visibility, r.guild_id
             FROM forum_thread t
             INNER JOIN forum_room r ON r.id = t.room_id
             WHERE t.id = :id',
            ['id' => $threadId],
        );

        if ($thread === false || !$this->mayViewRoom($thread, $userId, $guildIds, $isModerator, true)) {
            return null;
        }

        return $thread;
    }

    /** @return list<array<string, mixed>> */
    public function postsForThread(int $threadId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT p.id, p.author_id, p.quoted_post_id, p.body, p.created_at, p.edited_at,
                    COALESCE(u.display_name, 'Gelöschtes Konto') AS author_name
             FROM forum_post p
             LEFT JOIN cms_user u ON u.id = p.author_id
             WHERE p.thread_id = :thread_id
             ORDER BY p.created_at, p.id",
            ['thread_id' => $threadId],
        );
    }

    /** @return array<string, mixed>|null */
    public function editablePost(int $threadId, int $postId, int $authorId): ?array
    {
        $post = $this->connection->fetchAssociative(
            "SELECT p.id, p.thread_id, p.author_id, p.body, p.quoted_post_id, p.created_at, p.edited_at,
                    t.title, t.version,
                    CASE WHEN p.id = (
                        SELECT first_post.id FROM forum_post first_post
                        WHERE first_post.thread_id = t.id
                        ORDER BY first_post.created_at, first_post.id
                        LIMIT 1
                    ) THEN 1 ELSE 0 END AS is_question
             FROM forum_post p
             INNER JOIN forum_thread t ON t.id = p.thread_id
             WHERE p.id = :post_id AND p.thread_id = :thread_id AND p.author_id = :author_id
               AND t.state = 'open' AND t.solved_post_id IS NULL",
            ['post_id' => $postId, 'thread_id' => $threadId, 'author_id' => $authorId],
        );

        return $post === false ? null : $post;
    }

    public function editPost(
        int $threadId,
        int $postId,
        int $authorId,
        ?string $title,
        string $body,
        int $expectedVersion,
    ): void {
        $body = trim($body);
        new ForumPost($threadId, $authorId, $body);

        $this->connection->transactional(function (Connection $connection) use ($threadId, $postId, $authorId, $title, $body, $expectedVersion): void {
            $post = $connection->fetchAssociative(
                "SELECT p.id,
                        CASE WHEN p.id = (
                            SELECT first_post.id FROM forum_post first_post
                            WHERE first_post.thread_id = t.id
                            ORDER BY first_post.created_at, first_post.id
                            LIMIT 1
                        ) THEN 1 ELSE 0 END AS is_question
                 FROM forum_post p
                 INNER JOIN forum_thread t ON t.id = p.thread_id
                 WHERE p.id = :post_id AND p.thread_id = :thread_id AND p.author_id = :author_id
                   AND t.state = 'open' AND t.solved_post_id IS NULL",
                ['post_id' => $postId, 'thread_id' => $threadId, 'author_id' => $authorId],
            );
            if ($post === false) {
                throw new \DomainException('Der Beitrag kann nicht bearbeitet werden. Lade das Thema neu.');
            }

            $isQuestion = (int) $post['is_question'] === 1;
            $normalizedTitle = trim((string) $title);
            if ($isQuestion) {
                new ForumThread($authorId, $normalizedTitle);
            }

            $now = $this->now();
            $changed = $isQuestion
                ? $connection->executeStatement(
                    "UPDATE forum_thread
                     SET title = :title, version = version + 1, updated_at = :updated_at
                     WHERE id = :thread_id AND author_id = :author_id AND state = 'open'
                       AND solved_post_id IS NULL AND version = :version",
                    [
                        'title' => $normalizedTitle,
                        'updated_at' => $now,
                        'thread_id' => $threadId,
                        'author_id' => $authorId,
                        'version' => $expectedVersion,
                    ],
                )
                : $connection->executeStatement(
                    "UPDATE forum_thread
                     SET version = version + 1, updated_at = :updated_at
                     WHERE id = :thread_id AND state = 'open' AND solved_post_id IS NULL AND version = :version",
                    [
                        'updated_at' => $now,
                        'thread_id' => $threadId,
                        'version' => $expectedVersion,
                    ],
                );
            if ($changed !== 1) {
                throw new \DomainException('Das Thema wurde inzwischen geändert oder kann nicht mehr bearbeitet werden. Bitte lade es neu.');
            }

            if ($connection->executeStatement(
                'UPDATE forum_post SET body = :body, edited_at = :edited_at
                 WHERE id = :post_id AND thread_id = :thread_id AND author_id = :author_id',
                [
                    'body' => $body,
                    'edited_at' => $now,
                    'post_id' => $postId,
                    'thread_id' => $threadId,
                    'author_id' => $authorId,
                ],
            ) !== 1) {
                throw new \DomainException('Der Beitrag kann nicht bearbeitet werden. Lade das Thema neu.');
            }
        });
    }

    public function isSubscribed(int $threadId, int $userId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM forum_subscription WHERE thread_id = :thread_id AND user_id = :user_id',
            ['thread_id' => $threadId, 'user_id' => $userId],
        ) !== false;
    }

    public function setSubscription(int $threadId, int $userId, bool $subscribe): void
    {
        if ($subscribe) {
            $this->connection->executeStatement(
                'INSERT INTO forum_subscription (thread_id, user_id, created_at)
                 VALUES (:thread_id, :user_id, :created_at)
                 ON CONFLICT (thread_id, user_id) DO NOTHING',
                ['thread_id' => $threadId, 'user_id' => $userId, 'created_at' => $this->now()],
            );

            return;
        }

        $this->connection->delete('forum_subscription', ['thread_id' => $threadId, 'user_id' => $userId]);
    }

    public function createThread(int $roomId, int $authorId, string $title, string $body): int
    {
        $title = trim($title);
        $body = trim($body);
        new ForumThread($authorId, $title);
        new ForumPost(1, $authorId, $body);

        return $this->connection->transactional(function (Connection $connection) use ($roomId, $authorId, $title, $body): int {
            $now = $this->now();
            $threadId = (int) $connection->fetchOne(
                "INSERT INTO forum_thread (room_id, author_id, title, state, version, created_at, updated_at)
                 VALUES (:room_id, :author_id, :title, 'open', 0, :created_at, :updated_at)
                 RETURNING id",
                ['room_id' => $roomId, 'author_id' => $authorId, 'title' => $title, 'created_at' => $now, 'updated_at' => $now],
            );
            new ForumPost($threadId, $authorId, $body);
            $connection->insert('forum_post', [
                'thread_id' => $threadId,
                'author_id' => $authorId,
                'body' => $body,
                'created_at' => $now,
            ]);

            return $threadId;
        });
    }

    public function reply(int $threadId, int $authorId, string $body, ?int $quotedPostId, int $expectedVersion): int
    {
        $body = trim($body);
        new ForumPost($threadId, $authorId, $body, $quotedPostId);

        return $this->connection->transactional(function (Connection $connection) use ($threadId, $authorId, $body, $quotedPostId, $expectedVersion): int {
            if ($quotedPostId !== null && $connection->fetchOne(
                'SELECT 1 FROM forum_post WHERE id = :post_id AND thread_id = :thread_id',
                ['post_id' => $quotedPostId, 'thread_id' => $threadId],
            ) === false) {
                throw new \DomainException('Zitiert werden kann nur ein Beitrag aus diesem Thema.');
            }

            $now = $this->now();
            $changed = $connection->executeStatement(
                "UPDATE forum_thread
                 SET version = version + 1, updated_at = :updated_at
                 WHERE id = :id AND state = 'open' AND solved_post_id IS NULL AND version = :version",
                ['updated_at' => $now, 'id' => $threadId, 'version' => $expectedVersion],
            );
            if ($changed !== 1) {
                throw new \DomainException('Das Thema wurde inzwischen geändert oder nimmt keine Antworten mehr an. Bitte lade es neu.');
            }

            return (int) $connection->fetchOne(
                'INSERT INTO forum_post (thread_id, author_id, quoted_post_id, body, created_at)
                 VALUES (:thread_id, :author_id, :quoted_post_id, :body, :created_at)
                 RETURNING id',
                [
                    'thread_id' => $threadId,
                    'author_id' => $authorId,
                    'quoted_post_id' => $quotedPostId,
                    'body' => $body,
                    'created_at' => $now,
                ],
            );
        });
    }

    public function markSolved(int $threadId, int $postId, int $authorId, int $expectedVersion): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($threadId, $postId, $authorId, $expectedVersion): bool {
            if ($connection->fetchOne(
                'SELECT 1 FROM forum_post WHERE id = :post_id AND thread_id = :thread_id',
                ['post_id' => $postId, 'thread_id' => $threadId],
            ) === false) {
                return false;
            }

            return $connection->executeStatement(
                "UPDATE forum_thread
                 SET solved_post_id = :post_id, version = version + 1, updated_at = :updated_at
                 WHERE id = :thread_id AND author_id = :author_id AND state = 'open'
                   AND solved_post_id IS NULL AND version = :version",
                [
                    'post_id' => $postId,
                    'updated_at' => $this->now(),
                    'thread_id' => $threadId,
                    'author_id' => $authorId,
                    'version' => $expectedVersion,
                ],
            ) === 1;
        });
    }

    /** @return list<array<string, mixed>> */
    public function adminRooms(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT r.id, r.guild_id, r.title, r.visibility, g.name AS guild_name,
                    (SELECT COUNT(*) FROM forum_thread t WHERE t.room_id = r.id) AS thread_count
             FROM forum_room r
             LEFT JOIN guild g ON g.id = r.guild_id
             ORDER BY LOWER(r.title), r.id',
        );
    }

    /** @return array<string, mixed>|null */
    public function adminRoom(int $roomId): ?array
    {
        $room = $this->connection->fetchAssociative(
            'SELECT id, guild_id, title, visibility FROM forum_room WHERE id = :id',
            ['id' => $roomId],
        );

        return $room === false ? null : $room;
    }

    /** @return list<array<string, mixed>> */
    public function guildOptions(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, name FROM guild WHERE enabled = TRUE ORDER BY LOWER(name), id',
        );
    }

    public function saveRoom(?int $roomId, string $title, string $visibility, ?int $guildId): int
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Der Raumtitel ist erforderlich und darf höchstens 180 Zeichen lang sein.');
        }
        if (!in_array($visibility, ['public', 'members', 'guild', 'moderators'], true)) {
            throw new \InvalidArgumentException('Bitte wähle eine gültige Sichtbarkeit.');
        }
        if ($visibility === 'guild') {
            if ($guildId === null || $guildId < 1 || $this->connection->fetchOne(
                'SELECT 1 FROM guild WHERE id = :id AND enabled = TRUE',
                ['id' => $guildId],
            ) === false) {
                throw new \InvalidArgumentException('Für einen Gildenraum musst du eine aktive Gilde auswählen.');
            }
        } else {
            $guildId = null;
        }

        if ($roomId === null) {
            return (int) $this->connection->fetchOne(
                'INSERT INTO forum_room (guild_id, title, visibility, created_at)
                 VALUES (:guild_id, :title, :visibility, :created_at)
                 RETURNING id',
                ['guild_id' => $guildId, 'title' => $title, 'visibility' => $visibility, 'created_at' => $this->now()],
            );
        }

        $changed = $this->connection->update(
            'forum_room',
            ['guild_id' => $guildId, 'title' => $title, 'visibility' => $visibility],
            ['id' => $roomId],
        );
        if ($changed !== 1) {
            throw new \DomainException('Der Raum wurde nicht gefunden. Lade die Verwaltung neu.');
        }

        return $roomId;
    }

    public function moderateThread(int $threadId, int $actorId, string $state, string $reason, int $expectedVersion): bool
    {
        $reason = trim($reason);
        if (!in_array($state, ['open', 'locked', 'archived'], true) || $reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Wähle einen gültigen Status und gib einen Grund mit höchstens 500 Zeichen an.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($threadId, $actorId, $state, $reason, $expectedVersion): bool {
            $now = $this->now();
            $changed = $connection->executeStatement(
                'UPDATE forum_thread
                 SET state = :next_state,
                     solved_post_id = CASE WHEN :reopen_state = \'open\' THEN NULL ELSE solved_post_id END,
                     version = version + 1,
                     updated_at = :updated_at
                 WHERE id = :id AND version = :version',
                [
                    'next_state' => $state,
                    'reopen_state' => $state,
                    'updated_at' => $now,
                    'id' => $threadId,
                    'version' => $expectedVersion,
                ],
            );
            if ($changed !== 1) {
                return false;
            }

            $connection->insert('forum_moderation_audit', [
                'thread_id' => $threadId,
                'actor_id' => $actorId,
                'state' => $state,
                'reason' => $reason,
                'occurred_at' => $now,
            ]);

            return true;
        });
    }

    /** @return list<array<string, mixed>> */
    public function moderationHistory(int $threadId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT a.state, a.reason, a.occurred_at,
                    COALESCE(u.display_name, 'Gelöschtes Konto') AS actor_name
             FROM forum_moderation_audit a
             LEFT JOIN cms_user u ON u.id = a.actor_id
             WHERE a.thread_id = :thread_id
             ORDER BY a.occurred_at, a.id",
            ['thread_id' => $threadId],
        );
    }

    /**
     * @param list<int> $guildIds
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function visibilityWhere(?int $userId, array $guildIds, bool $isModerator): array
    {
        $parts = ['r.visibility = :public_visibility'];
        $parameters = ['public_visibility' => 'public'];
        if ($userId !== null) {
            $parts[] = 'r.visibility = :member_visibility';
            $parameters['member_visibility'] = 'members';
        }
        if ($isModerator) {
            $parts[] = 'r.visibility = :moderator_visibility';
            $parameters['moderator_visibility'] = 'moderators';
            $parts[] = 'r.visibility = :guild_visibility';
            $parameters['guild_visibility'] = 'guild';
        } else {
            $guildIds = array_values(array_unique(array_filter(
                array_map(static fn (int $id): int => $id, $guildIds),
                static fn (int $id): bool => $id > 0,
            )));
            if ($guildIds !== []) {
                $placeholders = [];
                foreach ($guildIds as $index => $guildId) {
                    $name = 'guild_'.$index;
                    $placeholders[] = ':'.$name;
                    $parameters[$name] = $guildId;
                }
                $parts[] = '(r.visibility = :guild_visibility AND r.guild_id IN ('.implode(', ', $placeholders).'))';
                $parameters['guild_visibility'] = 'guild';
            }
        }

        return ['('.implode(' OR ', $parts).')', $parameters];
    }

    /**
     * @param array<string, mixed> $room
     * @param list<int> $guildIds
     */
    private function mayViewRoom(array $room, ?int $userId, array $guildIds, bool $isModerator, bool $moduleEnabled): bool
    {
        $roomGuildId = $room['guild_id'] === null ? null : (int) $room['guild_id'];
        $viewerGuildId = $roomGuildId !== null && in_array($roomGuildId, $guildIds, true) ? $roomGuildId : null;

        return $this->roomPolicy->canView(
            (string) $room['visibility'],
            $roomGuildId,
            $viewerGuildId,
            $userId !== null,
            $isModerator,
            $moduleEnabled,
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
