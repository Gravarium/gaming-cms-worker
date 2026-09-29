<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;

final readonly class SearchResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public SearchDocument $document,
        public int $score,
        public array $reasons,
    ) {
    }

    public function getDocument(): SearchDocument { return $this->document; }
    public function getScore(): int { return $this->score; }
    /** @return list<string> */
    public function getReasons(): array { return $this->reasons; }
}
