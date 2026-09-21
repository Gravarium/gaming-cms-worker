<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MediaStorageCleanupJournal;
use PHPUnit\Framework\TestCase;

final class MediaStorageCleanupJournalTest extends TestCase
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

    public function testJournalIsIdempotentAndForgetRemovesCompletedCleanup(): void
    {
        $journal = new MediaStorageCleanupJournal($this->root());
        $journal->recordConnector('archive', 'video/file.mp4');
        $journal->recordConnector('archive', 'video/file.mp4');
        $journal->recordLocal('/uploads/media/content/file.txt');

        $pending = $journal->pending();
        self::assertCount(2, $pending);

        $journal->forget($pending[0]['id']);
        self::assertCount(1, $journal->pending());
    }

    public function testJournalRejectsTraversalInsteadOfPersistingIt(): void
    {
        $journal = new MediaStorageCleanupJournal($this->root());

        $this->expectException(\InvalidArgumentException::class);
        $journal->recordConnector('archive', 'video/../secret');
    }

    public function testJournalRejectsSymlinkRepairDirectory(): void
    {
        $root = $this->root();
        self::assertTrue(mkdir($root.'/var', 0700, true));
        $this->outside = sys_get_temp_dir().'/media-journal-outside-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->outside, 0700, true));
        if (!@symlink($this->outside, $root.'/var/media-repair')) {
            self::markTestSkipped('Symbolic links are unavailable in this test environment.');
        }

        $this->expectException(\DomainException::class);
        (new MediaStorageCleanupJournal($root))->recordLocal('/uploads/media/content/file.txt');
    }

    private function root(): string
    {
        if ($this->root === null) {
            $this->root = sys_get_temp_dir().'/media-journal-'.bin2hex(random_bytes(8));
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
