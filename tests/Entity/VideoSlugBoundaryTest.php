<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Entity\VideoPlaylist;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Validator\Constraints\Length;

final class VideoSlugBoundaryTest extends TestCase
{
    public function testSettersNormalizeRouteSafeSlugsAtTheirStorageBoundaries(): void
    {
        self::assertSame('guide-season-2', (new Video())->setSlug(' Guide-Season-2 ')->getSlug());
        self::assertSame('category-guides', (new VideoCategory())->setSlug(' Category-Guides ')->getSlug());
        self::assertSame('weekly-highlights', (new VideoPlaylist())->setSlug(' Weekly-Highlights ')->getSlug());

        $videoSlug = str_repeat('a', 200);
        $categorySlug = str_repeat('b', 140);
        $playlistSlug = str_repeat('c', 180);

        self::assertSame($videoSlug, (new Video())->setSlug($videoSlug)->getSlug());
        self::assertSame($categorySlug, (new VideoCategory())->setSlug($categorySlug)->getSlug());
        self::assertSame($playlistSlug, (new VideoPlaylist())->setSlug($playlistSlug)->getSlug());
    }

    public function testRejectsInvalidUtf8AndNonRouteSafeSlugFormsForEveryEntity(): void
    {
        foreach (['', "invalid\xFFutf8", 'two--hyphens', '-leading', 'trailing-', 'video/path', 'video_name', str_repeat('a', 513)] as $slug) {
            $this->assertRejected(static function () use ($slug): void { (new Video())->setSlug($slug); });
            $this->assertRejected(static function () use ($slug): void { (new VideoCategory())->setSlug($slug); });
            $this->assertRejected(static function () use ($slug): void { (new VideoPlaylist())->setSlug($slug); });
        }
    }

    public function testRejectsValuesBeyondEachMappedSlugColumn(): void
    {
        $this->assertRejected(static function (): void { (new Video())->setSlug(str_repeat('a', 201)); });
        $this->assertRejected(static function (): void { (new VideoCategory())->setSlug(str_repeat('a', 141)); });
        $this->assertRejected(static function (): void { (new VideoPlaylist())->setSlug(str_repeat('a', 181)); });
    }

    public function testVideoCategoryNameAndPlaylistTitleLengthsMatchTheirMappedColumns(): void
    {
        $this->assertLengthLimit(VideoCategory::class, 'name', 120);
        $this->assertLengthLimit(VideoPlaylist::class, 'title', 160);
    }

    private function assertLengthLimit(string $class, string $property, int $maximum): void
    {
        $attributes = (new ReflectionProperty($class, $property))->getAttributes(Length::class);

        self::assertCount(1, $attributes);
        self::assertSame($maximum, $attributes[0]->newInstance()->max);
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

        self::fail('An invalid video route slug must be rejected.');
    }
}
