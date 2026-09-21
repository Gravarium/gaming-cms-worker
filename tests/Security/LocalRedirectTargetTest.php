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

    public function testNotificationContractRejectsExternalLink(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LocalRedirectTarget::requireSafe('https://example.test/');
    }
}
