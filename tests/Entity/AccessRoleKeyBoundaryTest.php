<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRole;
use PHPUnit\Framework\TestCase;

final class AccessRoleKeyBoundaryTest extends TestCase
{
    public function testKeyAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiKey = str_repeat('r', 80);
        self::assertSame($asciiKey, (new AccessRole())->setKey($asciiKey)->getKey());

        $multibyteKey = str_repeat('🎮', 80);
        self::assertSame(320, strlen($multibyteKey));
        self::assertSame($multibyteKey, (new AccessRole())->setKey($multibyteKey)->getKey());
    }

    public function testNormalizedKeyAtColumnBoundaryKeepsLowercaseAndTrimBehavior(): void
    {
        $key = str_repeat('R', 80);
        $role = new AccessRole();

        self::assertSame(str_repeat('r', 80), $role->setKey('  '.$key.'  ')->getKey());
        self::assertSame('admin_role', $role->setKey('  Admin_Role  ')->getKey());
    }

    public function testRejectedKeysDoNotReplaceTheStoredValue(): void
    {
        $role = (new AccessRole())->setKey('editor_role');

        foreach ([str_repeat('r', 81), str_repeat('é', 81), str_repeat('🎮', 81), "\xFFkey", "\0admin", "admin\0"] as $candidate) {
            $this->assertRejected(static function () use ($role, $candidate): void {
                $role->setKey($candidate);
            });

            self::assertSame('editor_role', $role->getKey());
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

        self::fail('An out-of-bound access role key must be rejected.');
    }
}
