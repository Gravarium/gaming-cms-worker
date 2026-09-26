<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\Service\MediaStorageCleanupJournal;
use App\Service\MediaStorageCleanupRepairer;
use App\Service\S3ObjectStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class MediaStorageCleanupRepairerTest extends TestCase
{
    private ?string $root = null;
    private ?string $outside = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $this->removeTree($this->root);
        }
        if ($this->outside !== null) {
            $this->removeTree($this->outside);
        }
    }

    public function testLocalCleanupIsIdempotentAndJournalEntryIsRemoved(): void
    {
        $root = $this->root();
        $path = $root.'/public/uploads/media/content/file.txt';
        self::assertTrue(mkdir(dirname($path), 0777, true));
        file_put_contents($path, 'orphan');

        $journal = new MediaStorageCleanupJournal($root);
        $journal->recordLocal('/uploads/media/content/file.txt');

        $result = $this->repairer($journal, $root)->repairPending();

        self::assertSame(['repaired' => 1, 'failed' => 0], $result);
        self::assertFileDoesNotExist($path);
        self::assertSame([], $journal->pending());

        $journal->recordLocal('/uploads/media/content/file.txt');
        self::assertSame(['repaired' => 1, 'failed' => 0], $this->repairer($journal, $root)->repairPending());
        self::assertSame([], $journal->pending());
    }

    public function testNonPositiveRepairLimitDoesNotConsumeJournal(): void
    {
        $root = $this->root();
        $journal = new MediaStorageCleanupJournal($root);
        $journal->recordLocal('/uploads/media/content/file.txt');

        self::assertSame(
            ['repaired' => 0, 'failed' => 0],
            $this->repairer($journal, $root)->repairPending(0),
        );
        self::assertCount(1, $journal->pending());
    }

    public function testMalformedUtf8LocalLocationFailsClosed(): void
    {
        $root = $this->root();
        $journal = new MediaStorageCleanupJournal($root);
        $repairer = $this->repairer($journal, $root);
        $method = new \ReflectionMethod(MediaStorageCleanupRepairer::class, 'localPath');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke($repairer, "/uploads/media/content/\xC3\x28.txt");
    }

    public function testLocalCleanupDoesNotFollowSymlinkedParentDirectory(): void
    {
        $root = $this->root();
        self::assertTrue(mkdir($root.'/public/uploads/media', 0777, true));
        $this->outside = sys_get_temp_dir().'/media-cleanup-outside-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->outside, 0777, true));
        file_put_contents($this->outside.'/file.txt', 'must stay');
        if (!@symlink($this->outside, $root.'/public/uploads/media/content')) {
            self::markTestSkipped('Symbolic links are unavailable in this test environment.');
        }

        $journal = new MediaStorageCleanupJournal($root);
        $journal->recordLocal('/uploads/media/content/file.txt');

        self::assertSame(['repaired' => 0, 'failed' => 1], $this->repairer($journal, $root)->repairPending());
        self::assertFileExists($this->outside.'/file.txt');
        self::assertCount(1, $journal->pending());
    }

    private function repairer(MediaStorageCleanupJournal $journal, string $root): MediaStorageCleanupRepairer
    {
        $source = new class implements ExternalConnectorTargetSource {
            public function enabledFor(string $capability): array { return []; }
        };
        $registry = new ExternalConnectorRegistry($source);
        $adapters = new ExternalConnectorAdapterRegistry([]);
        $dispatcher = new ExternalMediaDispatcher(new ExternalConnectorExecutor($registry, $adapters), $registry, $adapters);
        $storage = new S3ObjectStorage(new MockHttpClient(), '', '', '', '', '', '');

        return new MediaStorageCleanupRepairer($journal, $dispatcher, $storage, $root);
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir().'/media-cleanup-'.bin2hex(random_bytes(8));
        }

        return $this->root;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
