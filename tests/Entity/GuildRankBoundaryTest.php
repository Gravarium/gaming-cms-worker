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

    public function testRejectedNamesDoNotReplaceTheStoredValue(): void
    {
        $state = (new GuildRank())->setName('Raider');

        foreach ([str_repeat('R', 101), str_repeat('é', 101), str_repeat(' ', 401), "invalid\xFFutf8", "Rank\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($state, $candidate): void {
                $state->setName($candidate);
            });

            self::assertSame('Raider', $state->getName());
        }
    }

    public function testNameKeepsItsCurrentTrimmingBehavior(): void
    {
        $state = new GuildRank();

        self::assertSame('Moderator', $state->setName('  Moderator  ')->getName());
        self::assertSame('', $state->setName('   ')->getName());
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
