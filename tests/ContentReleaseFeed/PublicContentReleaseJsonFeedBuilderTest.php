<?php

declare(strict_types=1);

namespace App\Tests\ContentReleaseFeed;

use App\ContentReleaseFeed\PublicContentReleaseJsonFeedBuilder;
use App\Entity\ContentRelease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicContentReleaseJsonFeedBuilderTest extends TestCase
{
    public function testFeedIncludesOnlyPublishedRowsAndPublicMetadata(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $name, array $parameters): string {
            return match ($name) {
                'app_public_content_release_index' => 'https://example.test/releases',
                'app_public_content_release_json_feed' => 'https://example.test/releases.jsonfeed',
                default => 'https://example.test/releases/'.$parameters['id'],
            };
        });
        $now = new \DateTimeImmutable('2026-10-15T00:00:00Z');
        $public = $this->release(14, ContentRelease::STATUS_PUBLISHED, '2026-10-12T10:30:00+02:00', 'Public & games', 'Public summary');
        $draft = $this->release(15, ContentRelease::STATUS_DRAFT, null, 'Private draft', 'Private summary');
        $future = $this->release(16, ContentRelease::STATUS_PUBLISHED, '2026-10-16T10:30:00Z', 'Future release', 'Not yet public');

        $feed = json_decode((new PublicContentReleaseJsonFeedBuilder($urls))->build([$public, $draft, $future], $now), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        self::assertSame('https://example.test/releases', $feed['home_page_url']);
        self::assertSame('https://example.test/releases.jsonfeed', $feed['feed_url']);
        self::assertCount(1, $feed['items']);
        self::assertSame('https://example.test/releases/14', $feed['items'][0]['id']);
        self::assertSame('Public & games', $feed['items'][0]['title']);
        self::assertSame('Public summary', $feed['items'][0]['content_text']);
        self::assertSame('2026-10-12T08:30:00+00:00', $feed['items'][0]['date_published']);
        self::assertArrayNotHasKey('authors', $feed['items'][0]);
    }

    public function testFeedIsBoundedAndSubstitutesInvalidUtf8Safely(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/releases/1');
        $now = new \DateTimeImmutable('2026-10-15T00:00:00Z');
        $releases = [];
        for ($id = 1; $id <= 51; ++$id) {
            $name = $id === 1 ? "Invalid \xB1 value" : 'Release '.$id;
            $releases[] = $this->release($id, ContentRelease::STATUS_PUBLISHED, '2026-10-10T00:00:00Z', $name, null);
        }

        $json = (new PublicContentReleaseJsonFeedBuilder($urls))->build($releases, $now);
        $feed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(50, $feed['items']);
        self::assertStringContainsString("Invalid \u{FFFD} value", $feed['items'][0]['title']);
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
