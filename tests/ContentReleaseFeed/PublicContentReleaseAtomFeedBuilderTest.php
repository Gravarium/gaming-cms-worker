<?php

declare(strict_types=1);

namespace App\Tests\ContentReleaseFeed;

use App\ContentReleaseFeed\PublicContentReleaseAtomFeedBuilder;
use App\Entity\ContentRelease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicContentReleaseAtomFeedBuilderTest extends TestCase
{
    public function testEmptyFeedUsesTheCurrentUtcTimeWithoutInventingLastModified(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/releases.atom');
        $now = new \DateTimeImmutable('2026-10-15T04:00:00+02:00');

        $feed = (new PublicContentReleaseAtomFeedBuilder($urls))->build([], $now);

        self::assertSame('https://example.test/releases.atom', $feed['feed_id']);
        self::assertSame('2026-10-15T02:00:00+00:00', $feed['updated']);
        self::assertNull($feed['last_modified']);
        self::assertSame([], $feed['entries']);
    }

    public function testOnlyAlreadyPublishedRowsAreProjectedInStableNewestFirstOrder(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $name, array $parameters): string {
            if ($name === 'app_public_content_release_atom') {
                return 'https://example.test/releases.atom';
            }

            return 'https://example.test/releases/'.$parameters['id'];
        });
        $now = new \DateTimeImmutable('2026-10-15T00:00:00+00:00');
        $older = $this->release(4, ContentRelease::STATUS_PUBLISHED, '2026-10-10T10:00:00Z', 'Older', null);
        $newer = $this->release(8, ContentRelease::STATUS_PUBLISHED, '2026-10-12T10:00:00Z', 'Newer', 'A public summary');
        $draft = $this->release(10, ContentRelease::STATUS_DRAFT, null, 'Draft', 'Private description');
        $future = $this->release(11, ContentRelease::STATUS_PUBLISHED, '2026-10-16T10:00:00Z', 'Future', 'Not yet public');

        $feed = (new PublicContentReleaseAtomFeedBuilder($urls))->build([$older, $draft, $future, $newer], $now);

        self::assertSame('2026-10-12T10:00:00+00:00', $feed['updated']);
        self::assertSame($newer->getPublishedAt(), $feed['last_modified']);
        self::assertSame(['Newer', 'Older'], array_column($feed['entries'], 'title'));
        self::assertSame('A public summary', $feed['entries'][0]['summary']);
        self::assertSame('Older', $feed['entries'][1]['summary']);
        self::assertSame('https://example.test/releases/8', $feed['entries'][0]['id']);
        self::assertArrayNotHasKey('createdBy', $feed['entries'][0]);
    }

    private function release(int $id, string $status, ?string $publishedAt, string $name, ?string $description): ContentRelease
    {
        $release = $this->createMock(ContentRelease::class);
        $release->method('getId')->willReturn($id);
        $release->method('getStatus')->willReturn($status);
        $release->method('getPublishedAt')->willReturn($publishedAt === null ? null : new \DateTimeImmutable($publishedAt));
        $release->method('getName')->willReturn($name);
        $release->method('getDescription')->willReturn($description);

        return $release;
    }
}
