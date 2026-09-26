<?php

declare(strict_types=1);

namespace App\Search;

interface SearchIndexAdapter
{
    public function moduleKey(): string;

    /** @return list<string> */
    public function sourceTypes(): array;

    /** @return iterable<SearchIndexRecord> */
    public function records(): iterable;
}
