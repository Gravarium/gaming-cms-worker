<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;
use App\Repository\Search\SearchDocumentRepository;

final readonly class SearchService
{
    public function __construct(
        private SearchDocumentRepository $documents,
        private LocalSearchVisibilityBoundary $visibility,
        private SearchRanker $ranker,
    ) {
    }

    /** @return list<SearchResult> */
    public function search(SearchQuery $query, SearchFilters $filters, SearchViewer $viewer, int $limit = 50): array
    {
        $results = [];
        foreach ($this->documents->findMatching($query->terms(), $filters, 1000) as $document) {
            if (!$this->visibility->canView($document, $viewer)) {
                continue;
            }
            $ranking = $this->ranker->rank($document, $query);
            $results[] = new SearchResult($document, $ranking->score, $ranking->reasons);
        }

        usort($results, static function (SearchResult $left, SearchResult $right): int {
            $score = $right->score <=> $left->score;
            if ($score !== 0) {
                return $score;
            }
            $date = $right->document->getSourceUpdatedAt() <=> $left->document->getSourceUpdatedAt();
            if ($date !== 0) {
                return $date;
            }

            return ($left->document->getId() ?? PHP_INT_MAX) <=> ($right->document->getId() ?? PHP_INT_MAX);
        });

        return array_slice($results, 0, max(1, min(100, $limit)));
    }

    /** @return list<SearchResult> */
    public function discover(SearchFilters $filters, SearchViewer $viewer, int $limit = 20): array
    {
        $results = [];
        foreach ($this->documents->findDiscoverable($filters, 1000) as $document) {
            if (!$this->visibility->canView($document, $viewer)) {
                continue;
            }
            $ranking = $this->ranker->recommend($document);
            $results[] = new SearchResult($document, $ranking->score, $ranking->reasons);
        }

        usort($results, static function (SearchResult $left, SearchResult $right): int {
            $score = $right->score <=> $left->score;
            if ($score !== 0) {
                return $score;
            }

            return $right->document->getSourceUpdatedAt() <=> $left->document->getSourceUpdatedAt();
        });

        return array_slice($results, 0, max(1, min(100, $limit)));
    }

    public function canView(SearchDocument $document, SearchViewer $viewer): bool
    {
        return $this->visibility->canView($document, $viewer);
    }
}
