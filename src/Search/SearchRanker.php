<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;

final class SearchRanker
{
    public function rank(SearchIndexRecord|SearchDocument $document, SearchQuery $query): SearchRanking
    {
        $title = mb_strtolower($document instanceof SearchDocument ? $document->getTitle() : $document->title);
        $body = mb_strtolower($document instanceof SearchDocument ? $document->getBody() : $document->body);
        $phrase = $query->raw;
        $score = 0;
        $reasons = [];

        if (str_contains($title, $phrase)) {
            $score += 80;
            $reasons[] = 'Titeltreffer';
        }
        if (str_contains($body, $phrase)) {
            $score += 20;
            $reasons[] = 'Phrasentreffer';
        }
        foreach ($query->terms() as $term) {
            if (str_contains($title, $term)) {
                $score += 30;
            } elseif (str_contains($body, $term)) {
                $score += 8;
            }
        }

        $popularity = $document instanceof SearchDocument ? $document->getPopularity() : $document->popularity;
        if ($popularity > 0) {
            $score += min(50, $popularity);
            $reasons[] = 'Beliebtheitssignal';
        }

        $facets = $document instanceof SearchDocument ? $document->getFacets() : $document->facets;
        if (in_array('featured', $facets, true) || in_array('pinned', $facets, true)) {
            $reasons[] = 'Hervorgehoben';
        }

        return new SearchRanking($score, array_values(array_unique($reasons)));
    }

    public function recommend(SearchDocument $document): SearchRanking
    {
        $score = min(100, $document->getPopularity());
        $reasons = $score > 0 ? ['Beliebtheitssignal'] : ['Aktuelles Discovery-Dokument'];
        if (in_array('featured', $document->getFacets(), true) || in_array('pinned', $document->getFacets(), true)) {
            $score += 25;
            $reasons[] = 'Hervorgehoben';
        }

        return new SearchRanking($score, array_values(array_unique($reasons)));
    }
}
