<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalConnectorHealthStatus;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorHealthStatusTest extends TestCase
{
    public function testStoresOnlySanitizedTargetHealth(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-18 14:10:00 UTC');
        $status = (new ExternalConnectorHealthStatus())
            ->setCapability('media')
            ->setTargetKey('PRIMARY ')
            ->setProviderKey('S3-Compatible ')
            ->setSuccessful(true)
            ->setCheckedAt($checkedAt);

        self::assertSame('media', $status->getCapability());
        self::assertSame('primary', $status->getTargetKey());
        self::assertSame('s3-compatible', $status->getProviderKey());
        self::assertTrue($status->isSuccessful());
        self::assertSame($checkedAt, $status->getCheckedAt());
    }
}
