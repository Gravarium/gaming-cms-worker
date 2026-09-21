<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExternalConnectorTarget;
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

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $this->removeTree($this->root);
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
