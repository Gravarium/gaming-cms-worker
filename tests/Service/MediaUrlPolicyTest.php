<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MediaUrlPolicy;
use PHPUnit\Framework\TestCase;

final class MediaUrlPolicyTest extends TestCase
{
    public function testAllowsPublicHttpMediaAndOwnedLocalPlayback(): void
    {
        $policy = new MediaUrlPolicy();

        self::assertTrue($policy->isSafeRemote('https://cdn.example.test/media/video.mp4'));
        self::assertTrue($policy->isSafePlayback('/uploads/media/video/file.mp4'));
    }

    public function testRejectsActiveSchemesCredentialsAndLocalNetworks(): void
    {
        $policy = new MediaUrlPolicy();

        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'https://user:secret@example.test/file.mp4',
            'http://localhost/file.mp4',
            'http://127.0.0.1/file.mp4',
            'http://10.1.2.3/file.mp4',
            'http://[::1]/file.mp4',
        ] as $url) {
            self::assertFalse($policy->isSafeRemote($url), $url);
        }
    }

    public function testRejectsPlaybackOutsideOwnedUploadNamespace(): void
    {
        $policy = new MediaUrlPolicy();

        self::assertFalse($policy->isSafePlayback('/private/video.mp4'));
        self::assertFalse($policy->isSafePlayback('/uploads/media/../secret'));
        self::assertFalse($policy->isSafePlayback('javascript:alert(1)'));
    }
}
