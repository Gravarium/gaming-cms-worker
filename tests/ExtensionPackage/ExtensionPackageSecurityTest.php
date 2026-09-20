<?php

declare(strict_types=1);

namespace App\Tests\ExtensionPackage;

use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionPackageInstaller;
use App\ExtensionPackage\ExtensionPackageVerifier;
use PHPUnit\Framework\TestCase;

final class ExtensionPackageSecurityTest extends TestCase
{
    private string $temp;
    private string $package;
    private string $keys;
    private string $secretKey;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir().'/cms-extension-'.bin2hex(random_bytes(6));
        $this->package = $this->temp.'/package';
        $this->keys = $this->temp.'/trusted.json';
        mkdir($this->package, 0700, true);

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        file_put_contents($this->keys, json_encode([
            'test-signer' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ], JSON_THROW_ON_ERROR));

        file_put_contents($this->package.'/theme.css', ':root{--test:1}');
        $manifest = json_encode([
            'schemaVersion' => 1,
            'type' => 'theme',
            'key' => 'signed-theme',
            'name' => 'Signed Theme',
            'version' => '1.2.3',
            'cmsConstraint' => '^1.0',
            'files' => ['theme.css' => hash_file('sha256', $this->package.'/theme.css')],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($this->package.'/manifest.json', $manifest);
        file_put_contents($this->package.'/signature.json', json_encode([
            'signer' => 'test-signer',
            'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $this->secretKey)),
        ], JSON_THROW_ON_ERROR));
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

    public function testVerifiesAndAtomicallyInstallsTrustedPackage(): void
    {
        $verifier = new ExtensionPackageVerifier($this->keys, new ExtensionCapabilityPolicy());
        $manifest = $verifier->verify($this->package);
        self::assertSame('signed-theme', $manifest->key);

        $installed = (new ExtensionPackageInstaller($verifier, $this->temp.'/installed'))->install($this->package);
        self::assertSame('1.2.3', $installed->version);
        self::assertFileExists($this->temp.'/installed/theme/signed-theme/theme.css');
    }

    public function testRejectsTamperedPayload(): void
    {
        file_put_contents($this->package.'/theme.css', 'tampered');

        $this->expectException(\DomainException::class);
        (new ExtensionPackageVerifier($this->keys, new ExtensionCapabilityPolicy()))->verify($this->package);
    }

    public function testRejectsUndeclaredFiles(): void
    {
        file_put_contents($this->package.'/hidden.php', '<?php');

        $this->expectException(\DomainException::class);
        (new ExtensionPackageVerifier($this->keys, new ExtensionCapabilityPolicy()))->verify($this->package);
    }

    public function testRejectsTraversalManifestPathEvenWhenSigned(): void
    {
        $data = json_decode((string) file_get_contents($this->package.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $data['files'] = ['../escape.php' => str_repeat('0', 64)];
        $manifest = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($this->package.'/manifest.json', $manifest);
        file_put_contents($this->package.'/signature.json', json_encode([
            'signer' => 'test-signer',
            'signature' => base64_encode(sodium_crypto_sign_detached($manifest, $this->secretKey)),
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\DomainException::class);
        (new ExtensionPackageVerifier($this->keys, new ExtensionCapabilityPolicy()))->verify($this->package);
    }
}
