<?php

declare(strict_types=1);

namespace App\Editorial;

final class CommunityRating
{
    /** @var array<int, int> */
    private array $ratings = [];

    public function submit(int $userId, int $rating, bool $isModeratedMember): void
    {
        if (!$isModeratedMember || $userId < 1 || $rating < 1 || $rating > 10) {
            throw new \DomainException('Community rating denied.');
        }
        $this->ratings[$userId] = $rating;
    }

    public function average(): ?float
    {
        return $this->ratings === [] ? null : array_sum($this->ratings) / count($this->ratings);
    }

    public function count(): int
    {
        return count($this->ratings);
    }
}
