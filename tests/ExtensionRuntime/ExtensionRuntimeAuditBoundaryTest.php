<?php

declare(strict_types=1);

namespace App\Tests\ExtensionRuntime;

use App\ExtensionRuntime\ExtensionRuntimeAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeAuditBoundaryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-audit-boundary-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testBoundedEventAppendsOnlyExpectedFieldsAndUsesPrivatePermissions(): void
    {
        $file = $this->directory.'/audit.jsonl';

        (new ExtensionRuntimeAudit($file))->record('module:example', 'content.read', 'success');

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(['at', 'extension', 'operation', 'status'], array_keys($entry));
        self::assertSame('module:example', $entry['extension']);
        self::assertSame('content.read', $entry['operation']);
        self::assertSame('success', $entry['status']);
        self::assertSame(0600, fileperms($file) & 0777);
    }

    public function testExistingAuditRecordsArePreservedAndNewRecordIsAppended(): void
    {
        $file = $this->directory.'/audit.jsonl';
        file_put_contents($file, '{"at":"2026-01-01T00:00:00+00:00","extension":"module:old","operation":"content.read","status":"success"}'."\n");

        (new ExtensionRuntimeAudit($file))->record('module:new', 'content.write', 'denied');

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $lines);
        self::assertStringContainsString('"extension":"module:old"', $lines[0]);
        self::assertStringContainsString('"extension":"module:new"', $lines[1]);
    }

    #[DataProvider('invalidEvents')]
    public function testRejectsOversizedMalformedAndControlSafeEventFields(
        string $extensionId,
        string $operation,
        string $status,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        (new ExtensionRuntimeAudit($this->directory.'/audit.jsonl'))->record($extensionId, $operation, $status);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidEvents(): iterable
    {
        yield 'oversized extension id' => ['module:'.str_repeat('a', 123), 'content.read', 'success'];
        yield 'oversized operation' => ['module:example', str_repeat('a', 97), 'success'];
        yield 'extension uppercase' => ['Module:example', 'content.read', 'success'];
        yield 'extension separator' => ['module/example', 'content.read', 'success'];
        yield 'operation separator' => ['content/read', 'success', 'success'];
        yield 'unknown status' => ['module:example', 'content.read', 'queued'];
        yield 'control extension' => ["module:\nexample", 'content.read', 'success'];
        yield 'invalid UTF-8 operation' => ['module:example', "content.\xC3\x28", 'success'];
    }

    public function testRejectsUnsafeAuditPathBeforeFilesystemAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExtensionRuntimeAudit($this->directory."/audit\n.jsonl");
    }

    public function testRejectsOversizedAuditPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExtensionRuntimeAudit($this->directory.'/'.str_repeat('a', 4097));
    }

    public function testRejectsAuditFileThatWouldExceedBoundedSize(): void
    {
        $file = $this->directory.'/audit.jsonl';
        file_put_contents($file, str_repeat('x', 1048576));

        $this->expectException(\RuntimeException::class);

        (new ExtensionRuntimeAudit($file))->record('module:example', 'content.read', 'success');
    }
}
