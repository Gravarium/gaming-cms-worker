<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use PHPUnit\Framework\TestCase;

final class AuditLogActionBoundaryTest extends TestCase
{
    public function testActionAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiAction = str_repeat('A', 80);
        self::assertSame($asciiAction, (new AuditLog())->setAction($asciiAction)->getAction());

        $multibyteAction = str_repeat('🎮', 80);
        self::assertSame(320, strlen($multibyteAction));
        self::assertSame($multibyteAction, (new AuditLog())->setAction($multibyteAction)->getAction());
    }

    public function testActionKeepsItsExactInBoundValue(): void
    {
        $log = new AuditLog();
        $value = '  user.updated  ';

        self::assertSame($log, $log->setAction($value));
        self::assertSame($value, $log->getAction());
    }

    public function testRejectedActionsDoNotReplaceTheStoredValue(): void
    {
        $log = (new AuditLog())->setAction('user.updated');

        foreach ([str_repeat('A', 81), str_repeat('é', 81), str_repeat('🎮', 81), "\xFFaction", "user\0updated"] as $candidate) {
            $this->assertRejected(static function () use ($log, $candidate): void {
                $log->setAction($candidate);
            });

            self::assertSame('user.updated', $log->getAction());
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

        self::fail('An out-of-bound audit action must be rejected.');
    }
}
