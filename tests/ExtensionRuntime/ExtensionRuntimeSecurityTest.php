<?php

declare(strict_types=1);

namespace App\Tests\ExtensionRuntime;

use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionRuntime\ExtensionOutboundUrlPolicy;
use App\ExtensionRuntime\ExtensionRuntimeAudit;
use App\ExtensionRuntime\ExtensionRuntimeContext;
use App\ExtensionRuntime\ExtensionRuntimeState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeSecurityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-runtime-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }

    public function testRuntimeContextUsesOnlySignedPackageIdentity(): void
    {
        $manifest = new ExtensionManifest('module', 'example', 'Example', '1.0.0', '^1.0', [], ['content.read']);
        self::assertSame('module:example', (new ExtensionRuntimeContext($manifest))->id());
    }

    #[DataProvider('protectedUrls')]
    public function testOutboundPolicyRejectsProtectedNetworks(string $url): void
    {
        $this->expectException(\DomainException::class);
        (new ExtensionOutboundUrlPolicy())->approve($url);
    }

    public static function protectedUrls(): iterable
    {
        yield ['http://example.com/data'];
        yield ['https://localhost/data'];
        yield ['https://127.0.0.1/data'];
        yield ['https://10.0.0.1/data'];
        yield ['https://169.254.169.254/latest/meta-data'];
        yield ['https://user:pass@example.com/data'];
        yield ['https://example.com:8443/data'];
    }

    public function testPublicLiteralAddressIsAllowedAndPinned(): void
    {
        $approved = (new ExtensionOutboundUrlPolicy())->approve('https://8.8.8.8/data');
        self::assertSame(['8.8.8.8'], $approved['ips']);
    }

    public function testCircuitOpensAfterFiveRuntimeFailures(): void
    {
        $state = new ExtensionRuntimeState($this->directory.'/state.json');
        for ($i = 0; $i < 5; ++$i) { $state->failure('module:example'); }

        $this->expectException(\DomainException::class);
        $state->assertCircuitClosed('module:example');
    }

    public function testQuotaIsPerExtensionAndOperation(): void
    {
        $state = new ExtensionRuntimeState($this->directory.'/state.json');
        for ($i = 0; $i < 20; ++$i) { $state->consume('module:example', 'notifications.send'); }

        $this->expectException(\DomainException::class);
        $state->consume('module:example', 'notifications.send');
    }

    public function testAuditContainsNoPayloadOrSecretFields(): void
    {
        $file = $this->directory.'/audit.jsonl';
        (new ExtensionRuntimeAudit($file))->record('module:example', 'content.read', 'success');

        $entry = json_decode(trim((string) file_get_contents($file)), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['at', 'extension', 'operation', 'status'], array_keys($entry));
        self::assertSame(0600, fileperms($file) & 0777);
    }
    public function testRuntimeStateRefusesSymbolicLinkFile(): void
    {
        $target = $this->directory.'/real-state.json';
        file_put_contents($target, '{}');
        $link = $this->directory.'/state-link.json';
        self::assertTrue(symlink($target, $link));

        $this->expectException(\RuntimeException::class);
        (new ExtensionRuntimeState($link))->failure('module:example');
    }

    public function testRuntimeAuditRefusesSymbolicLinkFile(): void
    {
        $target = $this->directory.'/real-audit.jsonl';
        file_put_contents($target, '');
        $link = $this->directory.'/audit-link.jsonl';
        self::assertTrue(symlink($target, $link));

        $this->expectException(\RuntimeException::class);
        (new ExtensionRuntimeAudit($link))->record('module:example', 'content.read', 'success');
    }
}
