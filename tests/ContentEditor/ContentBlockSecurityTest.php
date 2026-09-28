<?php

declare(strict_types=1);

namespace App\Tests\ContentEditor;

use App\ContentEditor\ContentBlockDocument;
use App\ContentEditor\ContentBlockPolicy;
use App\ContentEditor\ContentBlockRenderer;
use App\ContentEditor\OwnedMediaReferenceGateway;
use PHPUnit\Framework\TestCase;

final class ContentBlockSecurityTest extends TestCase
{
    public function testLegacyBodyIsConvertedToVersionedAllowedBlocks(): void
    {
        $documents = new ContentBlockDocument();
        $stored = $documents->normalizeForStorage("Erster Absatz\n\nZweiter <script>alert(1)</script>");

        self::assertStringStartsWith(ContentBlockDocument::PREFIX, $stored);
        $decoded = $documents->decode($stored);
        self::assertSame(1, $decoded['version']);
        self::assertSame(['text', 'text'], array_column($decoded['blocks'], 'type'));
        self::assertSame('Erster Absatz', $decoded['blocks'][0]['text']);
        self::assertSame('Zweiter <script>alert(1)</script>', $decoded['blocks'][1]['text']);
    }

    public function testRejectsOversizedLegacyBodyBeforeParagraphSplitting(): void
    {
        $body = implode("\n\n", array_fill(0, 101, str_repeat('a', 600)));
        self::assertGreaterThan(ContentBlockDocument::MAX_DOCUMENT_BYTES, strlen($body));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Editor-Dokument ist zu groß');
        (new ContentBlockDocument())->decode($body);
    }

    public function testOversizedBodyDoesNotReachRendererFallback(): void
    {
        $documents = new ContentBlockDocument();
        $gateway = new class implements OwnedMediaReferenceGateway {
            public function resolve(int $assetId): ?array
            {
                return null;
            }
        };
        $renderer = new ContentBlockRenderer($documents, $gateway);
        $body = str_repeat('x', ContentBlockDocument::MAX_DOCUMENT_BYTES + 1);

        self::assertSame('<p>Inhalt kann nicht angezeigt werden.</p>', $renderer->render($body));
    }

    public function testUnknownBlocksAndActiveLinkSchemesAreRejected(): void
    {
        $documents = new ContentBlockDocument();

        foreach ([
            [['type' => 'html', 'html' => '<b>raw</b>']],
            [['type' => 'link', 'text' => 'X', 'url' => 'javascript:alert(1)']],
            [['type' => 'link', 'text' => 'X', 'url' => '//evil.example.test/path']],
            [['type' => 'link', 'text' => 'X', 'url' => 'data:text/html,boom']],
        ] as $blocks) {
            try {
                $documents->normalizeForStorage($this->rawDocument($blocks));
                self::fail('Unsafe block document must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRendererEscapesAllUserTextAndUsesResolvedMediaUrl(): void
    {
        $gateway = new class implements OwnedMediaReferenceGateway {
            public function resolve(int $assetId): ?array
            {
                return $assetId === 7
                    ? ['id' => 7, 'url' => '/uploads/media/content/safe.png', 'title' => '<Owned title>', 'mime' => 'image/png']
                    : null;
            }
        };
        $documents = new ContentBlockDocument();
        $policy = new ContentBlockPolicy($documents, $gateway);
        $renderer = new ContentBlockRenderer($documents, $gateway);
        $stored = $policy->normalizeForStorage($this->rawDocument([
            ['type' => 'text', 'text' => '<script>alert(1)</script> & text'],
            ['type' => 'heading', 'level' => 2, 'text' => '<img src=x onerror=alert(1)>'],
            ['type' => 'link', 'text' => '<b>safe label</b>', 'url' => 'https://example.test/a?x=1&y=2'],
            ['type' => 'media', 'assetId' => 7, 'alt' => '"><script>bad</script>', 'caption' => '<svg onload=alert(1)>'],
        ]));

        $html = $renderer->render($stored);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<svg ', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('/uploads/media/content/safe.png', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;bad&lt;/script&gt;', $html);
        self::assertStringContainsString('https://example.test/a?x=1&amp;y=2', $html);
    }

    public function testMediaBlockRequiresOwnedResolvableAsset(): void
    {
        $gateway = new class implements OwnedMediaReferenceGateway {
            public function resolve(int $assetId): ?array { return null; }
        };
        $policy = new ContentBlockPolicy(new ContentBlockDocument(), $gateway);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Medienreferenz');
        $policy->normalizeForStorage($this->rawDocument([
            ['type' => 'media', 'assetId' => 999, 'alt' => '', 'caption' => ''],
        ]));
    }

    /** @param list<array<string,mixed>> $blocks */
    private function rawDocument(array $blocks): string
    {
        return ContentBlockDocument::PREFIX.json_encode(
            ['version' => ContentBlockDocument::VERSION, 'blocks' => $blocks],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
