<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchIndexReport
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $deleted,
        public int $skipped,
        public \DateTimeImmutable $completedAt,
    ) {
    }

    public function totalChanged(): int
    {
        return $this->created + $this->updated + $this->deleted;
    }
}
