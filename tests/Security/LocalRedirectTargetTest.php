<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LocalRedirectTarget;
use PHPUnit\Framework\TestCase;

final class LocalRedirectTargetTest extends TestCase
{
    public function testAcceptsLocalPathsAndQueries(): void
    {
        self::assertSame('/account/security?tab=sessions', LocalRedirectTarget::normalize('/account/security?tab=sessions', '/'));
    }

    public function testRejectsNetworkAndAmbiguousTargets(): void
    {
        foreach ([
            'https://example.test/',
            '//example.test/',
            '/\\example.test/',
            '/%5cexample.test/',
            '/%2fexample.test/',
            "/account\r\nLocation: https://example.test/",
        ] as $target) {
            self::assertSame('/', LocalRedirectTarget::normalize($target, '/'), $target);
        }
    }

    public function testFallbackIsValidatedBeforeItCanBeReturned(): void
    {
        $safeFallback = '/account/security?tab=sessions';
        self::assertSame($safeFallback, LocalRedirectTarget::normalize('/%5cexample.test/', $safeFallback));
        self::assertSame($safeFallback, LocalRedirectTarget::normalize('', $safeFallback));
        self::assertSame($safeFallback, LocalRedirectTarget::normalize(null, $safeFallback));

        foreach ([
            'https://example.test/',
            '//example.test/',
            '/\\example.test/',
            '/%5cexample.test/',
            '/%2fexample.test/',
            '/broken%',
            '/'.str_repeat('a', 2048),
            "/account\r\nLocation: https://example.test/",
        ] as $fallback) {
            self::assertNull(LocalRedirectTarget::normalize('/\\example.test/', $fallback), $fallback);
            self::assertNull(LocalRedirectTarget::normalize('', $fallback), $fallback);
            self::assertNull(LocalRedirectTarget::normalize(null, $fallback), $fallback);
        }
    }

    public function testNotificationContractRejectsExternalLink(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LocalRedirectTarget::requireSafe('https://example.test/');
    }
}
