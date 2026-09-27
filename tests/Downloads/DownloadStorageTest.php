<?php

declare(strict_types=1);

namespace App\Tests\Downloads;

use App\Downloads\DownloadPrivateStorage;
use App\Downloads\DownloadStorageUnavailable;
use App\Service\MediaMalwareScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DownloadStorageTest extends TestCase
{
    public function testStagedUploadCanBeDiscardedWithoutLeavingAFile(): void
    {
        $projectDir = $this->temporaryDirectory();
        $source = tempnam($projectDir, 'upload-');
        self::assertIsString($source);
        file_put_contents($source, 'safe archive bytes');

        $storage = new DownloadPrivateStorage(
            new MediaMalwareScanner('off', ''),
            $projectDir,
        );
        $stored = null;

        try {
            $stored = $storage->store(new UploadedFile(
                $source,
                'safe.zip',
                'application/zip',
                null,
                true,
            ));
            self::assertFileDoesNotExist($source);
            self::assertStringStartsWith('.staging/', $stored['staged_reference']);

            $storage->discard($stored);

            $this->expectException(DownloadStorageUnavailable::class);
            $storage->absolutePath($stored['reference']);
        } finally {
            if ($stored !== null) {
                try {
                    $storage->discard($stored);
                } catch (\Throwable) {
                }
            }
            $this->removeDirectory($projectDir);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/download-storage-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700, true));

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
