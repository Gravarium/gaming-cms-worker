<?php

declare(strict_types=1);

namespace App\Tests\ExtensionRuntime;

use App\ExtensionRuntime\ExtensionRuntimeState;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeStateBoundaryTest extends TestCase
{
    private string $directory;
    private string $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-runtime-state-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->file = $this->directory.'/state.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file) || is_link($this->file)) {
            unlink($this->file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOversizedStateIsRejectedByReadAndMutationWithoutRewritingIt(): void
    {
        $raw = str_repeat('x', ExtensionRuntimeState::MAX_STATE_BYTES + 1);
        file_put_contents($this->file, $raw);
        $state = new ExtensionRuntimeState($this->file);

        try {
            $state->sanitizedStatus('module:example');
            self::fail('Oversized runtime state must fail closed when read.');
        } catch (\DomainException) {
        }
        self::assertSame($raw, file_get_contents($this->file));

        try {
            $state->failure('module:example');
            self::fail('Oversized runtime state must fail closed before mutation.');
        } catch (\DomainException) {
        }
        self::assertSame($raw, file_get_contents($this->file));
    }

    public function testMalformedTypedStateIsRejectedWithoutRewritingIt(): void
    {
        $raw = '{"quotas":{"module:example|content.read":{"bucket":"now","count":1}},"circuits":{}}';
        file_put_contents($this->file, $raw);

        try {
            (new ExtensionRuntimeState($this->file))->consume('module:example', 'content.read');
            self::fail('Malformed persisted quota state must fail closed.');
        } catch (\DomainException) {
        }

        self::assertSame($raw, file_get_contents($this->file));
    }

    public function testEntryLimitRejectsNewCircuitBeforeChangingTheStateFile(): void
    {
        $quotas = [];
        $bucket = intdiv(time(), 60);
        $operations = ['content.read', 'media.read', 'notifications.send', 'http.outbound'];
        for ($extension = 0; $extension < intdiv(ExtensionRuntimeState::MAX_STATE_ENTRIES, count($operations)); ++$extension) {
            $extensionId = 'module:ext-'.str_pad((string) $extension, 4, '0', STR_PAD_LEFT);
            foreach ($operations as $operation) {
                $quotas[$extensionId.'|'.$operation] = ['bucket' => $bucket, 'count' => 1];
            }
        }

        self::assertCount(ExtensionRuntimeState::MAX_STATE_ENTRIES, $quotas);
        $raw = json_encode(['quotas' => (object) $quotas, 'circuits' => (object) []], JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(ExtensionRuntimeState::MAX_STATE_BYTES, strlen($raw));
        file_put_contents($this->file, $raw);

        try {
            (new ExtensionRuntimeState($this->file))->failure('module:overflow');
            self::fail('State entry capacity must fail closed before changing the file.');
        } catch (\DomainException) {
        }

        self::assertSame($raw, file_get_contents($this->file));
    }

    public function testInvalidIdentityAndUnknownOperationAreRejectedBeforeCreatingState(): void
    {
        $state = new ExtensionRuntimeState($this->file);

        try {
            $state->consume('module:1invalid', 'content.read');
            self::fail('Invalid extension identity must be rejected.');
        } catch (\InvalidArgumentException) {
        }
        self::assertFileDoesNotExist($this->file);

        try {
            $state->consume('module:example', 'unknown.operation');
            self::fail('Unknown runtime operation must be rejected.');
        } catch (\InvalidArgumentException) {
        }
        self::assertFileDoesNotExist($this->file);
    }

    public function testValidStateKeepsQuotaAndCircuitStatusReadable(): void
    {
        $state = new ExtensionRuntimeState($this->file);
        $state->consume('theme:fantasy', 'content.read');
        $state->failure('theme:fantasy');

        self::assertSame([
            'circuit' => 'closed',
            'failures' => 1,
            'requestsThisMinute' => 1,
        ], $state->sanitizedStatus('theme:fantasy'));
    }
}
