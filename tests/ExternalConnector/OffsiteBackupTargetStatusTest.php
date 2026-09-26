<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\OffsiteBackupTargetStatus;
use PHPUnit\Framework\TestCase;

final class OffsiteBackupTargetStatusTest extends TestCase
{
    public function testAcceptsValidStatusValues(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-18T12:05:00+00:00');
        $status = new OffsiteBackupTargetStatus(
            'primary-1',
            '20260918T120000Z-0123456789ab',
            $checkedAt,
            true,
            3,
            true,
        );

        self::assertSame('primary-1', $status->targetKey);
        self::assertSame('20260918T120000Z-0123456789ab', $status->backupId);
        self::assertSame($checkedAt, $status->checkedAt);
        self::assertTrue($status->successful);
        self::assertSame(3, $status->attempts);
        self::assertTrue($status->required);
    }

    public function testRejectsUnsafeTargetKeys(): void
    {
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            '',
            '20260918T120000Z-0123456789ab',
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            'primary/secret',
            '20260918T120000Z-0123456789ab',
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            str_repeat('a', 65),
            '20260918T120000Z-0123456789ab',
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            "primary\xFF",
            '20260918T120000Z-0123456789ab',
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
    }

    public function testRejectsUnsafeBackupIds(): void
    {
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            'primary',
            'not-a-backup-id',
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            'primary',
            "20260918T120000Z-0123456789ab\x00",
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            'primary',
            '20260918T120000Z-0123456789abcdef',
            new \DateTimeImmutable(),
            true,
            1,
            false,
        ));
    }

    public function testRejectsOutOfRangeAttempts(): void
    {
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            'primary',
            '20260918T120000Z-0123456789ab',
            new \DateTimeImmutable(),
            true,
            0,
            false,
        ));
        $this->assertInvalid(static fn (): OffsiteBackupTargetStatus => new OffsiteBackupTargetStatus(
            'primary',
            '20260918T120000Z-0123456789ab',
            new \DateTimeImmutable(),
            true,
            1_000_000_000,
            false,
        ));
    }

    private function assertInvalid(callable $factory): void
    {
        try {
            $factory();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('The offsite backup status was accepted.');
    }
}
