<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;

/**
 * Replaceable security seam for the future Fortress authorization service.
 * The local implementation is deliberately fail-closed and owns no private runtime.
 */
interface SearchVisibilityBoundary
{
    public function canIndex(SearchIndexRecord $record): bool;

    public function canView(SearchDocument $document, SearchViewer $viewer): bool;
}
