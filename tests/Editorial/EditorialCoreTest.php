<?php

declare(strict_types=1);

namespace App\Tests\Editorial;

use App\Editorial\CommunityRating;
use App\Editorial\EditorialRevisionHistory;
use App\Editorial\EditorialScore;
use PHPUnit\Framework\TestCase;

final class EditorialCoreTest extends TestCase
{
    public function testEditorialScoreCarriesTransparentContext(): void
    {
        $score = new EditorialScore(87, '40% systems, 30% narrative, 30% performance', 'PC', '1.2.0', ['Strong systems'], ['Uneven pacing'], 'Review copy supplied; no editorial control.');
        self::assertSame(87, $score->score);
        self::assertSame('PC', $score->platform);
    }

    public function testCommunityRatingStaysSeparateAndOnePerAccount(): void
    {
        $ratings = new CommunityRating();
        $ratings->submit(4, 8, true);
        $ratings->submit(4, 6, true);
        $ratings->submit(5, 10, true);
        self::assertSame(2, $ratings->count());
        self::assertSame(8.0, $ratings->average());
    }

    public function testUnmoderatedRatingFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        (new CommunityRating())->submit(4, 8, false);
    }

    public function testRevisionHistoryIsAppendOnly(): void
    {
        $history = new EditorialRevisionHistory();
        $history->append('Initial review', 2, new \DateTimeImmutable('2026-09-24T10:00:00Z'));
        $history->append('Score updated after performance patch', 2, new \DateTimeImmutable('2026-09-25T10:00:00Z'));
        self::assertSame(2, $history->revisions()[1]['version']);
        $this->expectException(\DomainException::class);
        $history->append('Backdated hidden edit', 2, new \DateTimeImmutable('2026-09-23T10:00:00Z'));
    }
}
