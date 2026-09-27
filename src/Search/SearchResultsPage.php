<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchResultsPage
{
    public const PAGE_SIZE = 20;
    public const MAX_CANDIDATE_DOCUMENTS = 1000;
    public const MAX_REQUESTED_PAGE = 1000;

    /** @var list<SearchResult> */
    public array $results;
    public int $page;
    public int $perPage;
    public int $total;
    public int $totalPages;

    /** @param list<SearchResult> $visibleResults */
    public function __construct(array $visibleResults, int $requestedPage)
    {
        if ($requestedPage < 1 || $requestedPage > self::MAX_REQUESTED_PAGE) {
            throw new \InvalidArgumentException('Die Suchseite muss innerhalb des gültigen Bereichs liegen.');
        }

        $this->total = count($visibleResults);
        $this->perPage = self::PAGE_SIZE;
        $this->totalPages = max(1, (int) ceil($this->total / self::PAGE_SIZE));
        $this->page = min($requestedPage, $this->totalPages);

        /** @var list<SearchResult> $pageResults */
        $pageResults = array_values(array_slice(
            $visibleResults,
            ($this->page - 1) * self::PAGE_SIZE,
            self::PAGE_SIZE,
        ));
        $this->results = $pageResults;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }
}
