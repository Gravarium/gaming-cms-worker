<?php

declare(strict_types=1);

namespace App\\Tests\\Entity;

use App\\Entity\\ModuleStorageSetting;
use PHPUnit\\Framework\\TestCase;

final class ModuleStorageSettingBoundaryTest extends TestCase
{
    public function testModuleKeyAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiKey = str_repeat('a', 50);
        self::assertSame($asciiKey, (new ModuleStorageSetting())->setModuleKey($asciiKey)->getModuleKey());

        $multibyteKey = str_repeat('🎮', 50);
        self::assertSame(200, strlen($multibyteKey));
        self::assertSame($multibyteKey, (new ModuleStorageSetting())->setModuleKey($multibyteKey)->getModuleKey());
    }

    public function testRejectedModuleKeysDoNotReplaceTheStoredKey(): void
    {
        $state = (new ModuleStorageSetting())->setModuleKey('video');

        foreach ([str_repeat('a', 51), str_repeat('a', 201), "invalid\\xFFutf8", "video\\0suffix"] as $key) {
            $this->assertRejected(static function () use ($state, $key): void {
                $state->setModuleKey($key);
            });

            self::assertSame('video', $state->getModuleKey());
        }
    }

    public function testExternalBaseUrlAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $prefix = 'https://example.invalid/';
        $asciiUrl = $prefix.str_repeat('a', 500 - mb_strlen($prefix, 'UTF-8'));
        self::assertSame(500, mb_strlen($asciiUrl, 'UTF-8'));
        self::assertSame($asciiUrl, (new ModuleStorageSetting())->setExternalBaseUrl($asciiUrl)->getExternalBaseUrl());

        $multibyteUrl = str_repeat('🎮', 500);
        self::assertSame(2000, strlen($multibyteUrl));
        self::assertSame($multibyteUrl, (new ModuleStorageSetting())->setExternalBaseUrl($multibyteUrl)->getExternalBaseUrl());
    }

    public function testRejectedExternalBaseUrlsDoNotReplaceTheStoredUrl(): void
    {
        $url = 'https://example.invalid/old';
        $state = (new ModuleStorageSetting())->setExternalBaseUrl($url);

        foreach ([str_repeat(' ', 2001), str_repeat('a', 501), "https://example.invalid/\\xFF", "https://example.invalid/\\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($state, $candidate): void {
                $state->setExternalBaseUrl($candidate);
            });

            self::assertSame($url, $state->getExternalBaseUrl());
        }
    }

    public function testExternalBaseUrlKeepsCurrentTrimmingAndEmptyNormalization(): void
    {
        $state = new ModuleStorageSetting();
        self::assertNull($state->setExternalBaseUrl(null)->getExternalBaseUrl());
        self::assertNull($state->setExternalBaseUrl('   ')->getExternalBaseUrl());
        self::assertSame('https://example.invalid/path', $state->setExternalBaseUrl('  https://example.invalid/path///  ')->getExternalBaseUrl());
    }

    /** @param \\Closure(): mixed $operation */
    private function assertRejected(\\Closure $operation): void
    {
        try {
            $operation();
        } catch (\\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('An out-of-bound module storage value must be rejected.');
    }
}
