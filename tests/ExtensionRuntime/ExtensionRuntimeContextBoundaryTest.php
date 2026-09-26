<?php

declare(strict_types=1);

namespace App\Tests\ExtensionRuntime;

use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionRuntime\ExtensionRuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeContextBoundaryTest extends TestCase
{
    public function testValidModuleIdentityIsPreserved(): void
    {
        $context = new ExtensionRuntimeContext($this->manifest('module', 'example'));

        self::assertSame('module:example', $context->id());
    }

    public function testValidThemeIdentityIsPreserved(): void
    {
        $context = new ExtensionRuntimeContext($this->manifest('theme', 'theme-pack'));

        self::assertSame('theme:theme-pack', $context->id());
    }

    #[DataProvider('invalidIdentities')]
    public function testRejectsMalformedAndOversizedIdentities(string $type, string $key): void
    {
        $this->expectException(\DomainException::class);

        new ExtensionRuntimeContext($this->manifest($type, $key));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'unknown type' => ['plugin', 'example'];
        yield 'empty type' => ['', 'example'];
        yield 'empty key' => ['module', ''];
        yield 'single character key' => ['module', 'a'];
        yield 'uppercase key' => ['module', 'Example'];
        yield 'underscore key' => ['module', 'bad_key'];
        yield 'slash key' => ['module', 'bad/key'];
        yield 'oversized key' => ['module', str_repeat('a', 41)];
        yield 'control key' => ['module', "bad\nkey"];
        yield 'invalid UTF-8 key' => ['module', "bad\xC3\x28"];
    }

    private function manifest(string $type, string $key): ExtensionManifest
    {
        return new ExtensionManifest(
            $type,
            $key,
            'Example',
            '1.0.0',
            '^1.0',
            [],
            ['content.read'],
        );
    }
}
