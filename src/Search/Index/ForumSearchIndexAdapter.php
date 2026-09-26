<?php

declare(strict_types=1);

namespace App\Search\Index;

use App\Search\SearchIndexAdapter;
use App\Search\SearchIndexRecord;
use App\Search\SearchModuleAvailability;
use Doctrine\DBAL\Connection;

final readonly class ForumSearchIndexAdapter implements SearchIndexAdapter
{
    public function __construct(
        private Connection $connection,
        private SearchModuleAvailability $availability,
    ) {
    }

    public function moduleKey(): string { return 'gaming'; }

    /** @return list<string> */
    public function sourceTypes(): array { return ['forum_thread']; }

    /** @return iterable<SearchIndexRecord> */
    public function records(): iterable
    {
        if (!$this->availability->isEnabled($this->moduleKey()) || !$this->tablesExist()) {
            return;
        }

        try {
            /** @var list<array<string, mixed>> $threads */
            $threads = $this->connection->fetchAllAssociative(
                'SELECT thread.id, thread.author_id, thread.title, thread.state, thread.updated_at, room.title AS room_title, room.visibility, room.guild_id
                 FROM forum_thread thread
                 INNER JOIN forum_room room ON room.id = thread.room_id
                 WHERE thread.state <> :archived
                 ORDER BY thread.updated_at DESC, thread.id ASC',
                ['archived' => 'archived'],
            );
            /** @var list<array<string, mixed>> $posts */
            $posts = $this->connection->fetchAllAssociative(
                'SELECT post.thread_id, post.body, post.created_at
                 FROM forum_post post
                 INNER JOIN forum_thread thread ON thread.id = post.thread_id
                 WHERE thread.state <> :archived
                 ORDER BY post.thread_id ASC, post.created_at ASC, post.id ASC',
                ['archived' => 'archived'],
            );
        } catch (\Throwable) {
            return;
        }

        $bodyByThread = [];
        $postCountByThread = [];
        foreach ($posts as $post) {
            $threadId = is_numeric($post['thread_id'] ?? null) ? (int) $post['thread_id'] : 0;
            $body = is_string($post['body'] ?? null) ? trim($post['body']) : '';
            if ($threadId < 1 || $body === '') {
                continue;
            }
            $bodyByThread[$threadId][] = $body;
            $postCountByThread[$threadId] = ($postCountByThread[$threadId] ?? 0) + 1;
        }

        foreach ($threads as $thread) {
            $id = is_numeric($thread['id'] ?? null) ? (int) $thread['id'] : 0;
            $title = is_string($thread['title'] ?? null) ? trim($thread['title']) : '';
            $visibility = $this->visibility((string) ($thread['visibility'] ?? ''));
            if ($id < 1 || $title === '' || $visibility === null) {
                continue;
            }
            $guildId = is_numeric($thread['guild_id'] ?? null) ? (int) $thread['guild_id'] : null;
            $roomTitle = is_string($thread['room_title'] ?? null) ? trim($thread['room_title']) : '';
            $authorId = is_numeric($thread['author_id'] ?? null) ? (int) $thread['author_id'] : null;
            $body = trim(implode("\n", array_merge([$title, $roomTitle], $bodyByThread[$id] ?? [])));
            $facets = ['forum'];
            if ((string) ($thread['state'] ?? '') === 'open') {
                $facets[] = 'open';
            }
            if ($roomTitle !== '') {
                $facets[] = 'room:'.$roomTitle;
            }

            yield new SearchIndexRecord(
                'forum_thread',
                $id,
                $this->moduleKey(),
                'forum',
                $title,
                $body === '' ? $title : $body,
                $roomTitle === '' ? null : $roomTitle,
                null,
                $visibility,
                $authorId,
                $guildId,
                $facets,
                min(40, 5 + (($postCountByThread[$id] ?? 0) * 2)),
                false,
                self::dateFromMixed($thread['updated_at'] ?? null),
            );
        }
    }

    private function tablesExist(): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist(['forum_room', 'forum_thread', 'forum_post']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function visibility(string $visibility): ?string
    {
        return match ($visibility) {
            'public' => 'public',
            'members' => 'authenticated',
            'guild' => 'guild',
            'moderators' => 'moderator',
            default => null,
        };
    }

    private static function dateFromMixed(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }

        return new \DateTimeImmutable('@0');
    }
}
