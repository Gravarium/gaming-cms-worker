<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MediaUrlPolicy;
use PHPUnit\Framework\TestCase;

final class MediaUrlPolicyOwnedPlaybackSecurityTest extends TestCase
{
    public function testRejectsPercentEncodedTraversalAndSeparatorsInOwnedPlaybackPaths(): void
    {
        $policy = new MediaUrlPolicy();

        foreach ([
            '/uploads/media/%2e%2e/%2e%2e/.env',
            '/uploads/media/%2E%2E/%2E%2E/private/video.mp4',
            '/uploads/media/.%2e/%2e%2e/private/video.mp4',
            '/uploads/media/video%2f..%2fsecret.mp4',
            '/uploads/media/%252e%252e/%252e%252e/.env',
        ] as $url) {
            self::assertFalse($policy->isSafePlayback($url), $url);
        }

        self::assertTrue($policy->isSafePlayback('/uploads/media/video/safe-clip-1234abcd.mp4'));
    }
}
