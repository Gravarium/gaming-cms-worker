<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Entity\Search\SearchDocument;
use App\Search\LocalSearchVisibilityBoundary;
use App\Search\SearchIndexRecord;
use App\Search\SearchModuleAvailability;
use App\Search\SearchViewer;
use PHPUnit\Framework\TestCase;

final class SearchVisibilityTest extends TestCase
{
    public function testVisibilityIsFailClosedAcrossAnonymousGuildAndOwnerBoundaries(): void
    {
        $availability = $this->createMock(SearchModuleAvailability::class);
        $availability->method('isEnabled')->willReturn(true);
        $policy = new LocalSearchVisibilityBoundary($availability);

        $public = new SearchDocument($this->record(SearchDocument::VISIBILITY_PUBLIC, null, null));
        $guild = new SearchDocument($this->record(SearchDocument::VISIBILITY_GUILD, null, 9));
        $private = new SearchDocument($this->record(SearchDocument::VISIBILITY_OWNER_OR_MODERATOR, 7, null));

        self::assertTrue($policy->canView($public, new SearchViewer(null, false, false, [])));
        self::assertFalse($policy->canView($guild, new SearchViewer(7, true, false, [])));
        self::assertTrue($policy->canView($guild, new SearchViewer(7, true, false, [9])));
        self::assertTrue($policy->canView($private, new SearchViewer(7, true, false, [])));
        self::assertFalse($policy->canView($private, new SearchViewer(8, true, false, [])));
        self::assertTrue($policy->canView($private, new SearchViewer(8, true, true, [])));
    }

    public function testDisabledModuleAndUnknownVisibilityNeverExposeDocuments(): void
    {
        $availability = $this->createMock(SearchModuleAvailability::class);
        $availability->method('isEnabled')->willReturn(false);
        $policy = new LocalSearchVisibilityBoundary($availability);
        $document = new SearchDocument($this->record(SearchDocument::VISIBILITY_PUBLIC, null, null));

        self::assertFalse($policy->canView($document, new SearchViewer(null, false, false, [])));
        self::assertFalse($policy->canIndex($this->record(SearchDocument::VISIBILITY_PUBLIC, null, null)));
    }

    private function record(string $visibility, ?int $ownerId, ?int $guildId): SearchIndexRecord
    {
        return new SearchIndexRecord(
            'forum_thread', 1, 'gaming', 'forum', 'Thread', 'Searchable body', null, null,
            $visibility, $ownerId, $guildId, ['forum'], 1, false, new \DateTimeImmutable(),
        );
    }
}
