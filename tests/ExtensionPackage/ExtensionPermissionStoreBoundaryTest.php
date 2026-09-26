<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionPackage\ExtensionPermissionStore;
use PHPUnit\Framework\TestCase;

final class ExtensionPermissionStoreBoundaryTest extends TestCase
{
    private string $directory;
    private string $file;
    private ExtensionManifest $manifest;
    private ExtensionPermissionStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-permission-boundary-'.bin2hex(random_bytes(6));
        $this->file = $this->directory.'/permissions.json';
        $this->manifest = new ExtensionManifest(
            'module',
            'example',
            'Example',
            '1.0.0',
            '^1.0',
            [],
            ['content.read'],
        );
        $this->store = new ExtensionPermissionStore($this->file, new ExtensionCapabilityPolicy());
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file.'.lock'] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function testRejectsUnsafePermissionStorePathsBeforeFilesystemAccess(): void
    {
        foreach ([
            'relative/permissions.json',
            $this->file."\x00",
            $this->file."\xFF",
            '/'.str_repeat('a', 4001),
        ] as $path) {
            $this->assertInvalidStorePath($path);
        }
    }

    public function testRejectsOversizedPermissionFileBeforeDecoding(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->file, str_repeat('x', 262_145));

        $this->expectException(\DomainException::class);
        $this->store->approved($this->manifest);
    }

    public function testRejectsMalformedJsonState(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->file, '{');

        $this->expectException(\DomainException::class);
        $this->store->approved($this->manifest);
    }

    public function testFailsClosedForInvalidPermissionShapes(): void
    {
        mkdir($this->directory, 0700, true);

        foreach ([
            '[]',
            '{"not-an-extension":["content.read"]}',
            '{"module:../example":["content.read"]}',
            '{"module:example":["content.read","content.read"]}',
            '{"module:example":["php.execute"]}',
            '{"module:example":{"content.read":true}}',
        ] as $contents) {
            file_put_contents($this->file, $contents);

            self::assertSame([], $this->store->approved($this->manifest));
        }
    }

    public function testFailsClosedForOverlargeExtensionMaps(): void
    {
        mkdir($this->directory, 0700, true);
        $permissions = [];
        for ($index = 0; $index < 501; ++$index) {
            $permissions['module:extension-'.$index] = [];
        }
        file_put_contents($this->file, json_encode($permissions, JSON_THROW_ON_ERROR));

        self::assertSame([], $this->store->approved($this->manifest));
    }

    private function assertInvalidStorePath(string $path): void
    {
        try {
            new ExtensionPermissionStore($path, new ExtensionCapabilityPolicy());
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);

            return;
        }

        self::fail('Unsafe permission store path was accepted.');
    }
}
