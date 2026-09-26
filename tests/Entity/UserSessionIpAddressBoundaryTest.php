<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserSession;
use PHPUnit\Framework\TestCase;

final class UserSessionIpAddressBoundaryTest extends TestCase
{
    public function testConstructorAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiAddress = str_repeat('1', 45);
        self::assertSame($asciiAddress, (new UserSession(new User(), 'session-id', $asciiAddress, null))->getIpAddress());

        $multibyteAddress = str_repeat('🎮', 45);
        self::assertSame(180, strlen($multibyteAddress));
        self::assertSame($multibyteAddress, (new UserSession(new User(), 'session-id', $multibyteAddress, null))->getIpAddress());

        self::assertNull((new UserSession(new User(), 'session-id', null, null))->getIpAddress());
    }

    public function testConstructorRejectsInvalidIpAddresses(): void
    {
        foreach ([str_repeat('1', 46), str_repeat('é', 46), str_repeat('🎮', 46), "\xFFaddress", "\0address", "address\0"] as $candidate) {
            $this->assertRejected(static function () use ($candidate): void {
                new UserSession(new User(), 'session-id', $candidate, null);
            });
        }
    }

    public function testTouchPreservesSessionStateWhenIpAddressIsRejected(): void
    {
        $session = new UserSession(new User(), 'session-id', '127.0.0.1', 'Original agent');
        $lastSeenAt = $session->getLastSeenAt();

        foreach ([str_repeat('1', 46), str_repeat('é', 46), str_repeat('🎮', 46), "\xFFaddress", "\0address", "address\0"] as $candidate) {
            $this->assertRejected(static function () use ($session, $candidate): void {
                $session->touch($candidate, 'Changed agent');
            });

            self::assertSame($lastSeenAt, $session->getLastSeenAt());
            self::assertSame('127.0.0.1', $session->getIpAddress());
            self::assertSame('Original agent', $session->getUserAgent());
        }
    }

    public function testTouchKeepsNullAndExactInBoundIpValues(): void
    {
        $session = new UserSession(new User(), 'session-id', null, null);
        $multibyteAddress = str_repeat('🎮', 45);

        $session->touch($multibyteAddress, 'Updated agent');

        self::assertSame($multibyteAddress, $session->getIpAddress());
        self::assertSame('Updated agent', $session->getUserAgent());
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

        self::fail('An invalid or out-of-bound session IP address must be rejected.');
    }
}
