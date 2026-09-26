<?php

declare(strict_types=1);

namespace App\Search\Index;

use App\Repository\VideoRepository;
use App\Search\SearchIndexAdapter;
use App\Search\SearchIndexRecord;
use App\Search\SearchModuleAvailability;

final readonly class VideoSearchIndexAdapter implements SearchIndexAdapter
{
    public function __construct(
        private VideoRepository $videos,
        private SearchModuleAvailability $availability,
    ) {
    }

    public function moduleKey(): string { return 'video'; }

    /** @return list<string> */
    public function sourceTypes(): array { return ['video']; }

    /** @return iterable<SearchIndexRecord> */
    public function records(): iterable
    {
        if (!$this->availability->isEnabled($this->moduleKey())) {
            return;
        }

        foreach ($this->videos->findPublished() as $video) {
            if ($video->getId() === null || ($video->getCategory() !== null && !$video->getCategory()->isEnabled())) {
                continue;
            }

            $facets = ['video'];
            if ($video->isFeatured()) {
                $facets[] = 'featured';
            }
            if ($video->getCategory() !== null) {
                $facets[] = 'category:'.$video->getCategory()->getSlug();
            }
            foreach ($video->getPlaylists() as $playlist) {
                if ($playlist->isEnabled()) {
                    $facets[] = 'playlist:'.$playlist->getSlug();
                }
            }

            $body = trim(implode("\n", array_filter([
                $video->getDescription(),
                $video->getDurationLabel(),
            ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

            yield new SearchIndexRecord(
                'video',
                $video->getId(),
                $this->moduleKey(),
                'video',
                $video->getTitle(),
                $body === '' ? $video->getTitle() : $body,
                $video->getDescription(),
                '/videos/'.rawurlencode($video->getSlug()),
                'public',
                null,
                null,
                array_values(array_unique($facets)),
                $video->isFeatured() ? 30 : 10,
                false,
                $video->getCreatedAt(),
            );
        }
    }
}
