<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Entity\Search\SearchDocument;
use App\Search\SearchIndexRecord;
use App\Search\SearchResult;
use App\Search\SearchResultsPage;
use PHPUnit\Framework\TestCase;

final class SearchResultsPageTest extends TestCase
{
    public function testSlicesVisibleResultsAndClampsRequestsToTheLastPage(): void
    {
        $results = $this->results(23);

        $first = new SearchResultsPage($results, 1);
        self::assertSame(23, $first->total);
        self::assertSame(2, $first->totalPages);
        self::assertSame(1, $first->page);
        self::assertCount(20, $first->results);
        self::assertFalse($first->hasPrevious());
        self::assertTrue($first->hasNext());

        $second = new SearchResultsPage($results, 2);
        self::assertSame(2, $second->page);
        self::assertCount(3, $second->results);
        self::assertTrue($second->hasPrevious());
        self::assertFalse($second->hasNext());

        $pastLastPage = new SearchResultsPage($results, 999);
        self::assertSame(2, $pastLastPage->page);
        self::assertSame($second->results, $pastLastPage->results);
    }

    public function testEmptyPageAndInvalidRequestedPagesStayBounded(): void
    {
        $empty = new SearchResultsPage([], 1);
        self::assertSame(0, $empty->total);
        self::assertSame(1, $empty->totalPages);
        self::assertSame(1, $empty->page);
        self::assertSame([], $empty->results);

        foreach ([0, SearchResultsPage::MAX_REQUESTED_PAGE + 1] as $invalidPage) {
            try {
                new SearchResultsPage([], $invalidPage);
                self::fail('Invalid page number should be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return list<SearchResult> */
    private function results(int $count): array
    {
        $results = [];
        for ($index = 1; $index <= $count; ++$index) {
            $document = new SearchDocument(new SearchIndexRecord(
                'unit-search-result',
                $index,
                'content',
                'news',
                'Search result '.$index,
                'Visible body',
                null,
                null,
                SearchDocument::VISIBILITY_PUBLIC,
                null,
                null,
                [],
                0,
                false,
                new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ));
            $results[] = new SearchResult($document, 10, []);
        }

        return $results;
    }
}
