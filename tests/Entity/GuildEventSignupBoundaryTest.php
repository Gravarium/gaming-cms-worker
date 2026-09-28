<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildEventSignup;
use PHPUnit\Framework\TestCase;

final class GuildEventSignupBoundaryTest extends TestCase
{
    public function testRoleAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiRole = str_repeat('R', 20);
        self::assertSame($asciiRole, (new GuildEventSignup())->setRole($asciiRole)->getRole());

        $multibyteRole = str_repeat('🎮', 20);
        self::assertSame(80, strlen($multibyteRole));
        self::assertSame($multibyteRole, (new GuildEventSignup())->setRole($multibyteRole)->getRole());
    }

    public function testRejectedRolesDoNotReplaceTheStoredValue(): void
    {
        $signup = (new GuildEventSignup())->setRole('tank');

        foreach ([str_repeat('R', 21), str_repeat('é', 21), str_repeat('🎮', 21), "bad\xFFrole", "tank\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($signup, $candidate): void {
                $signup->setRole($candidate);
            });

            self::assertSame('tank', $signup->getRole());
        }
    }

    public function testRoleKeepsItsExactInBoundValue(): void
    {
        $signup = new GuildEventSignup();
        $value = '  ranged-damage  ';

        self::assertSame($signup, $signup->setRole($value));
        self::assertSame($value, $signup->getRole());
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

        self::fail('An out-of-bound signup role must be rejected.');
    }
}
