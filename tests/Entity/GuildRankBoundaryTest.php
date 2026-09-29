<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildRank;
use PHPUnit\Framework\TestCase;

final class GuildRankBoundaryTest extends TestCase
{
    public function testNameAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiName = str_repeat('R', 100);
        self::assertSame($asciiName, (new GuildRank())->setName($asciiName)->getName());

        $multibyteName = str_repeat('🎮', 100);
        self::assertSame(400, strlen($multibyteName));
        self::assertSame($multibyteName, (new GuildRank())->setName($multibyteName)->getName());
    }

    public function testOverlongNamesRemainAvailableForValidationButAreRejectedBeforePersistence(): void
    {
        $rank = new GuildRank();

        foreach ([str_repeat('R', 101), str_repeat('é', 101)] as $candidate) {
            $rank->setName($candidate);
            self::assertSame($candidate, $rank->getName());
            $this->assertRejected(static function () use ($rank): void {
                $rank->assertNameColumnBoundary();
            });
        }
    }

    public function testNameKeepsItsCurrentTrimmingBehavior(): void
    {
        $state = new GuildRank();

        self::assertSame('Moderator', $state->setName('  Moderator  ')->getName());
        self::assertSame('', $state->setName('   ')->getName());
    }

    public function testMalformedNamesRemainRejectedWithoutReplacingTheStoredValue(): void
    {
        $rank = (new GuildRank())->setName('Raider');

        foreach (["invalid\xFFutf8", "Rank\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($rank, $candidate): void {
                $rank->setName($candidate);
            });
            self::assertSame('Raider', $rank->getName());
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('An out-of-bound guild rank name must be rejected.');
    }
}
