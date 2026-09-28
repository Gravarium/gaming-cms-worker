<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildMember;
use PHPUnit\Framework\TestCase;

final class GuildMemberCharacterNameBoundaryTest extends TestCase
{
    public function testPreservesTrimAndAcceptsTheExactMappedNameBoundary(): void
    {
        $asciiName = str_repeat('C', 120);
        $member = (new GuildMember())->setCharacterName(' '.$asciiName.' ');

        self::assertSame($asciiName, $member->getCharacterName());

        $multibyteName = str_repeat('🎮', 120);
        $member->setCharacterName($multibyteName);

        self::assertSame($multibyteName, $member->getCharacterName());
        self::assertSame(120, mb_strlen($member->getCharacterName(), 'UTF-8'));
        self::assertSame(480, strlen($member->getCharacterName()));
    }

    public function testRejectedNamesLeaveTheStoredValueUnchanged(): void
    {
        $member = (new GuildMember())->setCharacterName('Existing character');

        foreach ([str_repeat('x', 121), str_repeat('🎮', 121), "\xFFinvalid-utf8", "embedded\0nul"] as $name) {
            $this->assertRejected(static function () use ($member, $name): void {
                $member->setCharacterName($name);
            });

            self::assertSame('Existing character', $member->getCharacterName());
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

        self::fail('An invalid guild member character name must be rejected.');
    }
}
