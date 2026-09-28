<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionPackageInstaller;
use App\ExtensionPackage\ExtensionPackageVerifier;
use PHPUnit\Framework\TestCase;

final class ExtensionPackageInstallerBoundaryTest extends TestCase
{
    private string $temp;
    private string $package;
    private string $keys;
    private string $secretKey;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir().'/cms-installer-boundary-'.bin2hex(random_bytes(6));
        $this->package = $this->temp.'/package';
        $this->keys = $this->temp.'/trusted.json';
        mkdir($this->package, 0700, true);

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        file_put_contents($this->keys, json_encode([
            'test-signer' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ], JSON_THROW_ON_ERROR));

        file_put_contents($this->package.'/theme.css', ':root{--test:1}');
        $this->writeSignedManifest([
            'theme.css' => hash('sha256', ':root{--test:1}'),
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->temp)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->temp);
    }

    public function testRejectsMoreThanFiveHundredStagedFilesBeforeActivation(): void
    {
        unlink($this->package.'/theme.css');
        $files = [];
        $hash = hash('sha256', 'payload');
        for ($index = 0; $index <= 500; ++$index) {
            $name = 'file-'.$index.'.txt';
            file_put_contents($this->package.'/'.$name, 'payload');
            $files[$name] = $hash;
        }
        $this->writeSignedManifest($files);

        $installer = new ExtensionPackageInstaller(
            new ExtensionPackageVerifier($this->keys, new ExtensionCapabilityPolicy()),
            $this->temp.'/installed',
        );

        $this->expectException(\DomainException::class);

        $installer->install($this->package);
    }

    /**
     * @param array<string, string> $files
     */
    private function writeSignedManifest(array $files): void
    {
        $manifest = json_encode([
            'schemaVersion' => 1,
            'type' => 'theme',
            'key' => 'signed-theme',
            'name' => 'Signed Theme',
            'version' => '1.2.3',
            'cmsConstraint' => '^1.0',
            'files' => $files,
            'capabilities' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($this->package.'/manifest.json', $manifest);
        file_put_contents($this->package.'/signature.json', json_encode([
            'signer' => 'test-signer',
            'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $this->secretKey)),
        ], JSON_THROW_ON_ERROR));
    }
}
