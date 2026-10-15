<?php

declare(strict_types=1);

namespace App\Tests\ContentReleaseFeed;

use App\ContentReleaseFeed\PublicContentReleaseRssFeedBuilder;
use App\Entity\ContentRelease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicContentReleaseRssFeedBuilderTest extends TestCase
{
    public function testBuildsBoundedEscapedFeedWithStableAbsoluteLinksAndUtcDates(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
            self::assertSame(UrlGeneratorInterface::ABSOLUTE_URL, $referenceType);

            return match ($route) {
                'app_home' => 'https://cms.example/',
                'app_public_content_release_rss' => 'https://cms.example/releases.rss',
                'app_public_content_release_show' => 'https://cms.example/releases/'.$parameters['id'],
                default => throw new \LogicException('Unexpected route '.$route),
            };
        });

        $first = $this->release(41, 'Release <One> & friends', 'Summary <safe> & sound', new \DateTimeImmutable('2026-10-15 10:00:00+03:00'));
        $releases = [$first];
        for ($id = 42; $id < 93; ++$id) {
            $releases[] = $this->release($id, 'Release '.$id, null, new \DateTimeImmutable('2026-10-15 08:00:00+00:00'));
        }

        $xml = (new PublicContentReleaseRssFeedBuilder($urls))->build($releases, new \DateTimeImmutable('2026-10-15 12:00:00+02:00'));

        self::assertStringContainsString('<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">', $xml);
        self::assertStringContainsString('<atom:link href="https://cms.example/releases.rss" rel="self" type="application/rss+xml" />', $xml);
        self::assertStringContainsString('<title>Release &lt;One&gt; &amp; friends</title>', $xml);
        self::assertStringContainsString('<description>Summary &lt;safe&gt; &amp; sound</description>', $xml);
        self::assertStringContainsString('<guid isPermaLink="false">content-release-41@gaming-cms</guid>', $xml);
        self::assertStringContainsString('<pubDate>Thu, 15 Oct 2026 07:00:00 +0000</pubDate>', $xml);
        self::assertSame(50, substr_count($xml, '<item>'));
        self::assertStringNotContainsString('https://cms.example/releases/91', $xml);

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
    }

    public function testBuildsAWellFormedEmptyFeed(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnMap([
            ['app_home', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://cms.example/'],
            ['app_public_content_release_rss', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://cms.example/releases.rss'],
        ]);

        $xml = (new PublicContentReleaseRssFeedBuilder($urls))->build([], new \DateTimeImmutable('2026-10-15 08:00:00+00:00'));
        $document = new \DOMDocument();

        self::assertTrue($document->loadXML($xml));
        self::assertSame(0, $document->getElementsByTagName('item')->length);
    }

    private function release(int $id, string $name, ?string $description, \DateTimeImmutable $publishedAt): ContentRelease
    {
        $release = (new ContentRelease())->setName($name)->setDescription($description);
        (new \ReflectionProperty(ContentRelease::class, 'id'))->setValue($release, $id);
        (new \ReflectionProperty(ContentRelease::class, 'publishedAt'))->setValue($release, $publishedAt);

        return $release;
    }
}
