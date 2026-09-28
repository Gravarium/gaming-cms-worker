<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityGate;
use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionPackage\ExtensionPermissionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionCapabilityGateBoundaryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-capability-gate-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
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

    public function testCapabilityRemainsDeniedUntilThePermissionStoreApprovesIt(): void
    {
        $store = $this->store();
        $gate = new ExtensionCapabilityGate($store);

        $this->expectException(\DomainException::class);

        $gate->require($this->manifest(), 'content.read');
    }

    public function testApprovedCapabilityStillPassesAndRevocationDeniesIt(): void
    {
        $store = $this->store();
        $manifest = $this->manifest();
        $gate = new ExtensionCapabilityGate($store);

        $store->grant($manifest, 'content.read');
        $gate->require($manifest, 'content.read');

        $store->revoke($manifest, 'content.read');

        $this->expectException(\DomainException::class);
        $gate->require($manifest, 'content.read');
    }

    #[DataProvider('invalidCapabilities')]
    public function testRejectsMalformedOversizedAndForbiddenRequests(string $capability): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionCapabilityGate($this->store()))->require($this->manifest(), $capability);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCapabilities(): iterable
    {
        yield 'empty' => [''];
        yield 'oversized' => [str_repeat('a', 65)];
        yield 'whitespace' => ['content read'];
        yield 'slash' => ['content/read'];
        yield 'control byte' => ["content.\nread"];
        yield 'invalid UTF-8' => ["content.\xC3\x28"];
        yield 'unknown capability' => ['content.unknown'];
        yield 'forbidden execution capability' => ['php.execute'];
    }

    private function store(): ExtensionPermissionStore
    {
        return new ExtensionPermissionStore(
            $this->directory.'/permissions.json',
            new ExtensionCapabilityPolicy(),
        );
    }

    private function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            'module',
            'example',
            'Example',
            '1.0.0',
            '^1.0',
            [],
            ['content.read'],
        );
    }
}
