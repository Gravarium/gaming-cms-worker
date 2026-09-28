<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildApplication;
use PHPUnit\Framework\TestCase;

final class GuildApplicationMessageBoundaryTest extends TestCase
{
    public function testPreservesTrimAndAcceptsTheExistingExactMessageMaximum(): void
    {
        $asciiMessage = str_repeat('M', 5000);
        $application = (new GuildApplication())->setMessage(' '.$asciiMessage.' ');

        self::assertSame($asciiMessage, $application->getMessage());
        self::assertSame(5000, mb_strlen($application->getMessage(), 'UTF-8'));

        $multibyteMessage = str_repeat('🎮', 5000);
        $application->setMessage($multibyteMessage);

        self::assertSame($multibyteMessage, $application->getMessage());
        self::assertSame(5000, mb_strlen($application->getMessage(), 'UTF-8'));
        self::assertSame(20000, strlen($application->getMessage()));
    }

    public function testRejectedMessagesLeaveTheStoredValueUnchanged(): void
    {
        $storedMessage = 'An existing application message with enough content.';
        $application = (new GuildApplication())->setMessage($storedMessage);

        foreach ([str_repeat('x', 5001), str_repeat('🎮', 5001), "\xFFinvalid-utf8", "embedded\0nul"] as $message) {
            $this->assertRejected(static function () use ($application, $message): void {
                $application->setMessage($message);
            });

            self::assertSame($storedMessage, $application->getMessage());
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

        self::fail('An invalid guild application message must be rejected.');
    }
}
