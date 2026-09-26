<?php

declare(strict_types=1);

namespace App\Search\Index;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use App\Search\SearchIndexAdapter;
use App\Search\SearchIndexRecord;
use App\Search\SearchModuleAvailability;

final readonly class ContentSearchIndexAdapter implements SearchIndexAdapter
{
    public function __construct(
        private ContentEntryRepository $entries,
        private SearchModuleAvailability $availability,
    ) {
    }

    public function moduleKey(): string { return 'content'; }

    /** @return list<string> */
    public function sourceTypes(): array { return ['content_news', 'content_page']; }

    /** @return iterable<SearchIndexRecord> */
    public function records(): iterable
    {
        if (!$this->availability->isEnabled($this->moduleKey())) {
            return;
        }

        foreach ($this->entries->findPublishedAll(5000) as $entry) {
            if (!$entry->isPubliclyListed() || $entry->isNoIndex() || $entry->getId() === null) {
                continue;
            }

            $type = $entry->getType() === ContentEntry::TYPE_NEWS ? ContentEntry::TYPE_NEWS : ContentEntry::TYPE_PAGE;
            $facets = [$type];
            if ($entry->isFeatured()) {
                $facets[] = 'featured';
            }
            if ($entry->isPinned()) {
                $facets[] = 'pinned';
            }
            if ($entry->getCategory() !== null) {
                $facets[] = 'category:'.$entry->getCategory()->getSlug();
            }
            foreach ($entry->getTags() as $tag) {
                $facets[] = 'tag:'.$tag->getSlug();
            }

            $body = trim(implode("\n", array_filter([
                $entry->getSubtitle(),
                $entry->getExcerpt(),
                $entry->getBody(),
            ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

            yield new SearchIndexRecord(
                'content_'.$type,
                $entry->getId(),
                $this->moduleKey(),
                $type,
                $entry->getTitle(),
                $body === '' ? $entry->getTitle() : $body,
                $entry->getExcerpt(),
                '/'.($type === ContentEntry::TYPE_NEWS ? 'news' : 'page').'/'.rawurlencode($entry->getSlug()),
                'public',
                $entry->getAuthor()?->getId(),
                null,
                array_values(array_unique($facets)),
                ($entry->isPinned() ? 30 : 0) + ($entry->isFeatured() ? 20 : 0),
                false,
                $entry->getUpdatedAt(),
            );
        }
    }
}
