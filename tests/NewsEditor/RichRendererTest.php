<?php

declare(strict_types=1);

namespace App\Tests\NewsEditor;

use App\ContentEditor\ContentBlockDocument;
use App\ContentEditor\ContentBlockPolicy;
use App\ContentEditor\ContentBlockRenderer;
use App\ContentEditor\OwnedMediaReferenceGateway;
use App\NewsEditor\RichDocument;
use PHPUnit\Framework\TestCase;

final class RichRendererTest extends TestCase
{
    public function testRichArticleRendersOnlyAllowedHtmlAndResolvedMedia(): void
    {
        $media = new class implements OwnedMediaReferenceGateway {
            public function resolve(int $assetId): ?array
            {
                return $assetId === 7 ? ['id' => 7, 'url' => '/safe.png', 'title' => 'Safe', 'mime' => 'image/png'] : null;
            }
        };
        $legacy = new ContentBlockDocument();
        $input = RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [
            ['type' => 'paragraph', 'content' => [
                ['text' => '<script>alert(1)</script>', 'marks' => ['strong']],
                ['text' => ' Link', 'marks' => ['link'], 'href' => 'https://example.test/?a=1&b=2'],
            ]],
            ['type' => 'table', 'rows' => [[[ ['text' => '<img onerror=1>', 'marks' => []] ]]]],
            ['type' => 'media', 'assetId' => 7, 'alt' => '\"><svg onload=1>', 'caption' => '<unsafe>'],
            ['type' => 'callout', 'tone' => 'warning', 'content' => [['text' => '<img src=x>', 'marks' => []]]],
            ['type' => 'code', 'language' => 'html', 'text' => '<script>alert(1)</script>'],
            ['type' => 'separator'],
        ]], JSON_THROW_ON_ERROR);

        $normalized = (new ContentBlockPolicy($legacy, $media))->normalizeForStorage($input);
        $html = (new ContentBlockRenderer($legacy, $media))->render($normalized);
        self::assertStringContainsString('<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>', $html);
        self::assertStringContainsString('https://example.test/?a=1&amp;b=2', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('&lt;img onerror=1&gt;', $html);
        self::assertStringContainsString('src="/safe.png"', $html);
        self::assertStringContainsString('news-callout--warning', $html);
        self::assertStringContainsString('&lt;img src=x&gt;', $html);
        self::assertStringContainsString('<code data-language="html">&lt;script&gt;alert(1)&lt;/script&gt;</code>', $html);
        self::assertStringContainsString('<hr class="news-separator">', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<svg ', $html);
    }

    public function testUnownedMediaAndMalformedRichDocumentsFailClosed(): void
    {
        $media = new class implements OwnedMediaReferenceGateway {
            public function resolve(int $assetId): ?array { return null; }
        };
        $legacy = new ContentBlockDocument();
        $input = RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [
            ['type' => 'media', 'assetId' => 999, 'alt' => '', 'caption' => ''],
        ]], JSON_THROW_ON_ERROR);
        try {
            (new ContentBlockPolicy($legacy, $media))->normalizeForStorage($input);
            self::fail('Unowned media was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('', (new ContentBlockRenderer($legacy, $media))->render($input));
        }
        self::assertSame('<p>Inhalt kann nicht angezeigt werden.</p>', (new ContentBlockRenderer($legacy, $media))->render(RichDocument::PREFIX.'<script>'));
    }

    public function testTableHeaderScopeAndBodyOnlyCellsAreEscaped(): void
    {
        $media = new class implements OwnedMediaReferenceGateway {
            public function resolve(int $assetId): ?array { return null; }
        };
        $renderer = new \App\NewsEditor\RichRenderer(new RichDocument(), $media);
        $cell = [['text' => '<script>', 'marks' => []]];
        $rows = [[$cell], [$cell]];
        $header = RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [['type' => 'table', 'rows' => $rows]]], JSON_THROW_ON_ERROR);
        $html = $renderer->render($header);
        self::assertStringContainsString('<thead><tr><th scope="col">&lt;script&gt;</th></tr></thead>', $html);
        $bodyOnly = RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [['type' => 'table', 'rows' => $rows, 'header' => false]]], JSON_THROW_ON_ERROR);
        $html = $renderer->render($bodyOnly);
        self::assertStringNotContainsString('<thead>', $html);
        self::assertStringContainsString('<tbody><tr><td>&lt;script&gt;</td></tr>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
