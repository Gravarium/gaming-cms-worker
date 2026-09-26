<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Guild;
use PHPUnit\Framework\TestCase;

final class GuildServerNameBoundaryTest extends TestCase
{
    public function testPreservesTrimAndAcceptsTheExactMappedNameBoundary(): void
    {
        $asciiName = str_repeat('S', 120);
        $guild = (new Guild())->setServerName(' '.$asciiName.' ');

        self::assertSame($asciiName, $guild->getServerName());

        $multibyteName = str_repeat('🎮', 120);
        $guild->setServerName($multibyteName);

        self::assertSame($multibyteName, $guild->getServerName());
        self::assertSame(120, mb_strlen($guild->getServerName(), 'UTF-8'));
        self::assertSame(480, strlen($guild->getServerName()));
    }

    public function testRejectedNamesLeaveTheStoredValueUnchanged(): void
    {
        $guild = (new Guild())->setServerName('Existing server');

        foreach ([str_repeat('x', 121), str_repeat('🎮', 121), "\xFFinvalid-utf8", "embedded\0nul"] as $serverName) {
            $this->assertRejected(static function () use ($guild, $serverName): void {
                $guild->setServerName($serverName);
            });

            self::assertSame('Existing server', $guild->getServerName());
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

        self::fail('An invalid guild server name must be rejected.');
    }
}
