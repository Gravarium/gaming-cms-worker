<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Entity\Search\SearchDocument;
use App\Search\SearchIndexRecord;
use App\Search\SearchQuery;
use App\Search\SearchRanker;
use PHPUnit\Framework\TestCase;

final class SearchCoreTest extends TestCase
{
    public function testSearchQueryNormalizesTermsAndRejectsControls(): void
    {
        $query = SearchQuery::fromString('  Guild  Recruitment!  ');
        self::assertSame('guild recruitment', $query->raw);
        self::assertSame(['guild', 'recruitment'], $query->terms());

        $this->expectException(\InvalidArgumentException::class);
        SearchQuery::fromString("visible\nsecret");
    }

    public function testFingerprintChangesWhenIndexedContentChanges(): void
    {
        $first = $this->record('first body');
        $second = $this->record('changed body');

        self::assertNotSame($first->fingerprint(), $second->fingerprint());
    }

    public function testRankingExplainsTitleAndPopularitySignals(): void
    {
        $record = new SearchIndexRecord(
            'content_news', 1, 'content', 'news', 'Guild recruitment', 'Guild recruitment is open', null, '/news/guild',
            SearchDocument::VISIBILITY_PUBLIC, null, null, ['news', 'featured'], 20, false, new \DateTimeImmutable(),
        );
        $ranking = (new SearchRanker())->rank($record, SearchQuery::fromString('guild'));

        self::assertGreaterThan(0, $ranking->score);
        self::assertContains('Titeltreffer', $ranking->reasons);
        self::assertContains('Beliebtheitssignal', $ranking->reasons);
        self::assertContains('Hervorgehoben', $ranking->reasons);
    }

    private function record(string $body): SearchIndexRecord
    {
        return new SearchIndexRecord(
            'content_news', 1, 'content', 'news', 'Title', $body, null, '/news/title',
            SearchDocument::VISIBILITY_PUBLIC, null, null, ['news'], 0, false, new \DateTimeImmutable(),
        );
    }
}
