<?php

declare(strict_types=1);

namespace App\Tests\VideoSearch;

use App\Repository\PublicVideoSearchRepository;
use PHPUnit\Framework\TestCase;

final class PublicVideoSearchPageCountTest extends TestCase
{
    public function testPageCountRoundsUpAndIsBoundedWithoutIntegerOverflow(): void
    {
        $cases = [
            [0, 1],
            [1, 1],
            [PublicVideoSearchRepository::PAGE_SIZE, 1],
            [PublicVideoSearchRepository::PAGE_SIZE + 1, 2],
            [19_980, 999],
            [19_981, PublicVideoSearchRepository::MAX_PAGE],
            [20_000, PublicVideoSearchRepository::MAX_PAGE],
            [20_001, PublicVideoSearchRepository::MAX_PAGE],
            [PHP_INT_MAX, PublicVideoSearchRepository::MAX_PAGE],
        ];

        foreach ($cases as [$totalMatches, $expectedPages]) {
            self::assertSame($expectedPages, PublicVideoSearchRepository::boundedPageCount($totalMatches));
        }
    }

    public function testNegativeMatchCountsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicVideoSearchRepository::boundedPageCount(-1);
    }
}
