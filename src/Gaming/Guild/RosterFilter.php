<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

final readonly class RosterFilter
{
    public function __construct(
        public ?int $gameId = null,
        public ?string $role = null,
        public ?string $characterClass = null,
        public ?string $status = null,
    ) {
        if ($this->gameId !== null && $this->gameId < 1) {
            throw new \InvalidArgumentException('Game id must be positive.');
        }
        foreach ([$this->role, $this->characterClass, $this->status] as $value) {
            if ($value !== null && trim($value) === '') {
                throw new \InvalidArgumentException('Roster filters cannot contain blank values.');
            }
        }
    }
}
