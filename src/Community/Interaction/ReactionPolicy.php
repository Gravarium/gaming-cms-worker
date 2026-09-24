<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final class ReactionPolicy
{
    public const ALLOWED = ['like', 'helpful', 'love', 'laugh', 'wow'];
    public const MAX_DISTINCT_PER_USER_AND_TARGET = 3;

    /** @param list<string> $current */
    public function assertCanAdd(array $current, string $reaction): void
    {
        if (!in_array($reaction, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Unknown reaction type.');
        }
        if (in_array($reaction, $current, true)) {
            throw new \DomainException('Reaction already exists.');
        }
        if (count(array_unique($current)) >= self::MAX_DISTINCT_PER_USER_AND_TARGET) {
            throw new \DomainException('Reaction limit reached.');
        }
    }
}
