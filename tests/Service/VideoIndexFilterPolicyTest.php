<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VideoIndexFilterPolicy;
use PHPUnit\Framework\TestCase;

final class VideoIndexFilterPolicyTest extends TestCase
{
    public function testCategorySlugMatchesPersistedColumnLength(): void
    {
        self::assertTrue(VideoIndexFilterPolicy::acceptsCategorySlug(str_repeat('x', 140)));
        self::assertFalse(VideoIndexFilterPolicy::acceptsCategorySlug(str_repeat('x', 141)));
    }

    public function testPlaylistSlugMatchesPersistedColumnLength(): void
    {
        self::assertTrue(VideoIndexFilterPolicy::acceptsPlaylistSlug(str_repeat('x', 180)));
        self::assertFalse(VideoIndexFilterPolicy::acceptsPlaylistSlug(str_repeat('x', 181)));
    }

    public function testSlugLengthsCountUtf8Characters(): void
    {
        self::assertTrue(VideoIndexFilterPolicy::acceptsCategorySlug(str_repeat('é', 140)));
        self::assertFalse(VideoIndexFilterPolicy::acceptsCategorySlug(str_repeat('é', 141)));
        self::assertTrue(VideoIndexFilterPolicy::acceptsPlaylistSlug(str_repeat('é', 180)));
        self::assertFalse(VideoIndexFilterPolicy::acceptsPlaylistSlug(str_repeat('é', 181)));
    }

    public function testMalformedUtf8SlugsAreRejected(): void
    {
        self::assertFalse(VideoIndexFilterPolicy::acceptsCategorySlug("\xC3\x28"));
        self::assertFalse(VideoIndexFilterPolicy::acceptsPlaylistSlug("\xC3\x28"));
    }
}
