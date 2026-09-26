<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionPackageInventory;
use App\ExtensionPackage\ExtensionPackageVerifier;
use App\ExtensionPackage\ExtensionPermissionStore;
use App\ExtensionRuntime\ExtensionRuntimeState;
use PHPUnit\Framework\TestCase;

final class ExtensionPackageInventoryBoundaryTest extends TestCase
{
    private string $root;
    private ExtensionPackageVerifier $verifier;
    private ExtensionPermissionStore $permissions;
    private ExtensionRuntimeState $runtimeState;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/cms-extension-inventory-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);

        $policy = new ExtensionCapabilityPolicy();
        $this->verifier = new ExtensionPackageVerifier($this->root.'/trusted-keys.json', $policy);
        $this->permissions = new ExtensionPermissionStore($this->root.'/permissions.json', $policy);
        $this->runtimeState = new ExtensionRuntimeState($this->root.'/runtime.json');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($this->root);
    }

    public function testReportsMalformedPackagesWithoutLeakingUnboundedMetadata(): void
    {
        mkdir($this->root.'/module/zeta', 0700, true);
        mkdir($this->root.'/module/alpha', 0700, true);
        mkdir($this->root.'/theme/skin', 0700, true);

        $items = $this->inventory()->all();

        self::assertSame(['module', 'module', 'theme'], array_column($items, 'type'));
        self::assertSame(['alpha', 'zeta', 'skin'], array_column($items, 'key'));
        self::assertSame(['invalid', 'invalid', 'invalid'], array_column($items, 'status'));
        self::assertSame(['Ungültiges externes Paket', 'Ungültiges externes Paket', 'Ungültiges externes Paket'], array_column($items, 'name'));
    }

    public function testIgnoresFilesAndSymlinkedPackageDirectories(): void
    {
        mkdir($this->root.'/module', 0700, true);
        file_put_contents($this->root.'/module/README.txt', 'not a package');

        $outside = $this->root.'/outside';
        mkdir($outside, 0700, true);
        if (!symlink($outside, $this->root.'/module/linked')) {
            self::markTestSkipped('Symlinks are unavailable in this test environment.');
        }

        self::assertSame([], $this->inventory()->all());
    }

    public function testFailsClosedWhenThePackageCountExceedsTheBound(): void
    {
        mkdir($this->root.'/module', 0700, true);
        for ($index = 0; $index < 501; ++$index) {
            mkdir($this->root.'/module/package-'.$index, 0700);
        }

        self::assertSame([], $this->inventory()->all());
    }

    public function testRejectsUnsafeInstallRootsBeforeFilesystemAccess(): void
    {
        foreach ([
            'relative/extensions',
            $this->root."\x00",
            $this->root."\xFF",
            '/'.str_repeat('a', 4001),
        ] as $unsafeRoot) {
            self::assertSame([], $this->inventory($unsafeRoot)->all());
        }
    }

    private function inventory(?string $root = null): ExtensionPackageInventory
    {
        return new ExtensionPackageInventory(
            $this->verifier,
            $this->permissions,
            $this->runtimeState,
            $root ?? $this->root,
        );
    }
}
