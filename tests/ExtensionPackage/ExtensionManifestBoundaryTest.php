<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionManifest;
use PHPUnit\Framework\TestCase;

final class ExtensionManifestBoundaryTest extends TestCase
{
    public function testAcceptsBoundedManifestAndPreservesValues(): void
    {
        $files = ['src/Module.php' => str_repeat('a', 64)];
        $capabilities = ['content.read', 'media.read'];

        $manifest = new ExtensionManifest(
            'module',
            'example',
            'Example extension',
            '1.0.0',
            '^1.0',
            $files,
            $capabilities,
        );

        self::assertSame($files, $manifest->files);
        self::assertSame($capabilities, $manifest->capabilities);
        self::assertSame('module', $manifest->type);
        self::assertSame('example', $manifest->key);
    }

    public function testRejectsUnsafeIdentityAndMetadata(): void
    {
        foreach ([
            fn (): ExtensionManifest => $this->manifest(type: 'plugin'),
            fn (): ExtensionManifest => $this->manifest(key: '../example'),
            fn (): ExtensionManifest => $this->manifest(key: str_repeat('a', 41)),
            fn (): ExtensionManifest => $this->manifest(name: "Example\x00extension"),
            fn (): ExtensionManifest => $this->manifest(name: "\xFF"),
            fn (): ExtensionManifest => $this->manifest(name: str_repeat('n', 121)),
            fn (): ExtensionManifest => $this->manifest(version: '1.0'),
            fn (): ExtensionManifest => $this->manifest(version: '1234567890.0.0'),
            fn (): ExtensionManifest => $this->manifest(cmsConstraint: '1.0'),
        ] as $factory) {
            $this->assertInvalid($factory);
        }
    }

    public function testRejectsUnsafeFilesAndCapabilities(): void
    {
        foreach ([
            fn (): ExtensionManifest => $this->manifest(files: ['../Module.php' => str_repeat('a', 64)]),
            fn (): ExtensionManifest => $this->manifest(files: ['src/Module.php' => str_repeat('a', 63)]),
            fn (): ExtensionManifest => $this->manifest(capabilities: ['php.execute']),
            fn (): ExtensionManifest => $this->manifest(capabilities: ['content.read', 'content.read']),
        ] as $factory) {
            $this->assertInvalid($factory);
        }
    }

    private function assertInvalid(callable $factory): void
    {
        try {
            $factory();
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);

            return;
        }

        self::fail('Unsafe extension manifest input was accepted.');
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $capabilities
     */
    private function manifest(
        string $type = 'module',
        string $key = 'example',
        string $name = 'Example extension',
        string $version = '1.0.0',
        string $cmsConstraint = '^1.0',
        array $files = [],
        array $capabilities = [],
    ): ExtensionManifest {
        return new ExtensionManifest($type, $key, $name, $version, $cmsConstraint, $files, $capabilities);
    }
}
