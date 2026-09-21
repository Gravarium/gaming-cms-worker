<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityGate;
use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionPackage\ExtensionPermissionStore;
use PHPUnit\Framework\TestCase;

final class ExtensionCapabilitySandboxTest extends TestCase
{
    private string $directory;
    private ExtensionManifest $manifest;
    private ExtensionPermissionStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-permissions-'.bin2hex(random_bytes(6));
        $policy = new ExtensionCapabilityPolicy();
        $this->store = new ExtensionPermissionStore($this->directory.'/permissions.json', $policy);
        $this->manifest = new ExtensionManifest(
            'module',
            'example',
            'Example',
            '1.0.0',
            '^1.0',
            ['payload.txt' => str_repeat('0', 64)],
            ['content.read', 'notifications.send'],
        );
    }

    protected function tearDown(): void
    {
        foreach (['permissions.json', 'permissions.json.lock'] as $name) {
            $path = $this->directory.'/'.$name;
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testCapabilitiesAreDeniedUntilIndividuallyApproved(): void
    {
        $gate = new ExtensionCapabilityGate($this->store);

        $this->expectException(\DomainException::class);
        $gate->require($this->manifest, 'content.read');
    }

    public function testRequestedCapabilityCanBeGrantedAndRevokedAtomically(): void
    {
        $gate = new ExtensionCapabilityGate($this->store);
        $this->store->grant($this->manifest, 'content.read');

        $gate->require($this->manifest, 'content.read');
        self::assertSame(['content.read'], $this->store->approved($this->manifest));
        self::assertSame(0600, fileperms($this->directory.'/permissions.json') & 0777);

        $this->store->revoke($this->manifest, 'content.read');
        self::assertFalse($this->store->allows($this->manifest, 'content.read'));
    }

    public function testUnrequestedAndExecutionCapabilitiesCanNeverBeGranted(): void
    {
        foreach (['media.write', 'php.execute', 'database.migrate', 'secrets.read'] as $capability) {
            try {
                $this->store->grant($this->manifest, $capability);
                self::fail('Capability unexpectedly granted: '.$capability);
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPermissionStoreRejectsSymlinkTarget(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
        $outside = $this->directory.'/outside.json';
        file_put_contents($outside, '{}');
        symlink($outside, $this->directory.'/permissions.json');

        try {
            $this->expectException(\DomainException::class);
            $this->store->grant($this->manifest, 'content.read');
        } finally {
            @unlink($this->directory.'/permissions.json');
            @unlink($outside);
        }
    }

    public function testPolicyRejectsForbiddenCapabilityInSignedManifest(): void
    {
        $this->expectException(\DomainException::class);
        (new ExtensionCapabilityPolicy())->normalize(['content.read', 'process.execute']);
    }
}
