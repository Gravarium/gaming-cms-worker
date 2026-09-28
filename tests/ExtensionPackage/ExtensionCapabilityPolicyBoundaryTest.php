<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionCapabilityPolicyBoundaryTest extends TestCase
{
    public function testNormalizationPreservesAllowlistOrderingAndCollapsesDuplicates(): void
    {
        $normalized = (new ExtensionCapabilityPolicy())->normalize([
            'settings.write',
            'content.read',
            'content.read',
        ]);

        self::assertSame(['content.read', 'settings.write'], $normalized);
    }

    public function testEmptyCapabilityListRemainsAllowedAndNormalizesToEmpty(): void
    {
        self::assertSame([], (new ExtensionCapabilityPolicy())->normalize([]));
    }

    public function testRejectsCapabilityListAboveBound(): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionCapabilityPolicy())->normalize(array_fill(0, 33, 'content.read'));
    }

    #[DataProvider('invalidCapabilityValues')]
    public function testRejectsMalformedOversizedForbiddenAndUnknownValues(mixed $value): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionCapabilityPolicy())->normalize([$value]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCapabilityValues(): iterable
    {
        yield 'non string value' => [42];
        yield 'oversized value' => [str_repeat('a', 65)];
        yield 'control value' => ["content.\nread"];
        yield 'invalid UTF-8 value' => ["content.\xC3\x28"];
        yield 'unknown value' => ['content.unknown'];
        yield 'never grant value' => ['php.execute'];
    }

    public function testGrantabilityStillUsesDeclarableAllowlist(): void
    {
        $policy = new ExtensionCapabilityPolicy();

        self::assertTrue($policy->isGrantable('content.read'));
        self::assertFalse($policy->isGrantable('php.execute'));
        self::assertFalse($policy->isGrantable('content.unknown'));
    }
}
