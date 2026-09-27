<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;

final readonly class LocalSearchVisibilityBoundary implements SearchVisibilityBoundary
{
    public function __construct(private SearchModuleAvailability $availability)
    {
    }

    public function canIndex(SearchIndexRecord $record): bool
    {
        return $this->availability->isEnabled($record->moduleKey);
    }

    public function canView(SearchDocument $document, SearchViewer $viewer): bool
    {
        if (!$this->availability->isEnabled($document->getModuleKey())) {
            return false;
        }

        return match ($document->getVisibility()) {
            SearchDocument::VISIBILITY_PUBLIC => true,
            SearchDocument::VISIBILITY_AUTHENTICATED => $viewer->authenticated,
            SearchDocument::VISIBILITY_GUILD => $viewer->moderator || $viewer->belongsToGuild($document->getGuildId()),
            SearchDocument::VISIBILITY_MODERATOR => $viewer->moderator,
            SearchDocument::VISIBILITY_OWNER_OR_MODERATOR => $viewer->moderator || (
                $viewer->authenticated
                && $viewer->userId !== null
                && $document->getOwnerId() === $viewer->userId
            ),
            default => false,
        };
    }
}
