<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildTeam;
use PHPUnit\Framework\TestCase;

final class GuildTeamBoundaryTest extends TestCase
{
    public function testPreservesTrimAndAcceptsTheExactMappedNameBoundary(): void
    {
        $asciiName = str_repeat('T', 120);
        $team = (new GuildTeam())->setName(' '.$asciiName.' ');

        self::assertSame($asciiName, $team->getName());

        $multibyteName = str_repeat('🎮', 120);
        $team->setName($multibyteName);

        self::assertSame($multibyteName, $team->getName());
        self::assertSame(120, mb_strlen($team->getName(), 'UTF-8'));
        self::assertSame(480, strlen($team->getName()));
    }

    public function testRejectedNamesLeaveTheStoredValueUnchanged(): void
    {
        $team = (new GuildTeam())->setName('Existing team');

        foreach ([str_repeat('x', 121), str_repeat('🎮', 121), "\xFFinvalid-utf8", "embedded\0nul"] as $name) {
            $this->assertRejected(static function () use ($team, $name): void {
                $team->setName($name);
            });

            self::assertSame('Existing team', $team->getName());
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

        self::fail('An invalid guild team name must be rejected.');
    }
}
