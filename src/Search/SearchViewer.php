<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchViewer
{
    /** @param list<int> $guildIds */
    public function __construct(
        public ?int $userId,
        public bool $authenticated,
        public bool $moderator,
        public array $guildIds,
    ) {
        if ($this->userId !== null && $this->userId < 1) {
            throw new \InvalidArgumentException('Search viewer ids must be positive.');
        }
        foreach ($this->guildIds as $guildId) {
            if ($guildId < 1) {
                throw new \InvalidArgumentException('Search viewer guild ids must be positive.');
            }
        }
    }

    public function belongsToGuild(?int $guildId): bool
    {
        return $guildId !== null && in_array($guildId, $this->guildIds, true);
    }
}
