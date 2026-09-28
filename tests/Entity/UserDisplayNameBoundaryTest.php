<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserDisplayNameBoundaryTest extends TestCase
{
    public function testDisplayNameAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiName = str_repeat('P', 80);
        self::assertSame($asciiName, (new User())->setDisplayName($asciiName)->getDisplayName());

        $multibyteName = str_repeat('🎮', 80);
        self::assertSame(320, strlen($multibyteName));
        self::assertSame($multibyteName, (new User())->setDisplayName($multibyteName)->getDisplayName());
    }

    public function testTrimmedDisplayNameAtBoundaryKeepsCurrentNormalization(): void
    {
        $name = str_repeat('P', 80);
        $user = new User();

        self::assertSame($name, $user->setDisplayName('  '.$name.'  ')->getDisplayName());
        self::assertSame('Player One', $user->setDisplayName('  Player One  ')->getDisplayName());
        self::assertSame('', $user->setDisplayName('   ')->getDisplayName());
    }

    public function testRejectedDisplayNamesDoNotReplaceTheStoredValue(): void
    {
        $user = (new User())->setDisplayName('Player');

        foreach ([str_repeat('P', 81), str_repeat('é', 81), str_repeat('🎮', 81), "\xFFname", "\0name", "name\0"] as $candidate) {
            $this->assertRejected(static function () use ($user, $candidate): void {
                $user->setDisplayName($candidate);
            });

            self::assertSame('Player', $user->getDisplayName());
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

        self::fail('An out-of-bound display name must be rejected.');
    }
}
