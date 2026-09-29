<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchRanking
{
    /** @param list<string> $reasons */
    public function __construct(public int $score, public array $reasons)
    {
    }
}
