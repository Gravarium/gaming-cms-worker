<?php

declare(strict_types=1);

namespace App\Forum;

final readonly class ForumPost
{
    /** @param list<int> $mentionedUserIds */
    public function __construct(
        public int $threadId,
        public int $authorId,
        public string $body,
        public ?int $quotedPostId = null,
        public array $mentionedUserIds = [],
    ) {
        if ($threadId < 1 || $authorId < 1 || trim($body) === '' || mb_strlen($body) > 20_000) {
            throw new \InvalidArgumentException('Post identity and bounded body are required.');
        }
        if ($quotedPostId !== null && $quotedPostId < 1) {
            throw new \InvalidArgumentException('Invalid quoted post.');
        }
        if (count($mentionedUserIds) > 20 || count($mentionedUserIds) !== count(array_unique($mentionedUserIds))) {
            throw new \InvalidArgumentException('Mentions are bounded and unique.');
        }
        foreach ($mentionedUserIds as $userId) {
            if ($userId < 1) {
                throw new \InvalidArgumentException('Invalid mentioned account.');
            }
        }
    }
}
