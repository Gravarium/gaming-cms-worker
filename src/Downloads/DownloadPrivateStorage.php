<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Service\MediaMalwareScanner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class DownloadPrivateStorage
{
    /** @var list<string> */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
        'js', 'mjs', 'html', 'htm', 'svg', 'shtml', 'xhtml', 'xml', 'xsl', 'xslt',
    ];

    public function __construct(
        private MediaMalwareScanner $scanner,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * The upload is staged until the database transaction has succeeded.
     *
     * @return array{
     *     reference: string,
     *     staged_reference: string,
     *     filename: string,
     *     sha256: string,
     *     scan: string
     * }
     */
    public function store(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new \DomainException('Upload is invalid.');
        }

        $filename = $this->safeFilename($file->getClientOriginalName());
        $sha256 = hash_file('sha256', $file->getPathname());
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \RuntimeException('SHA-256 could not be calculated.');
        }

        $scan = $this->scanPath($file->getPathname());
        $month = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y/m');
        $reference = $month.'/'.$filename;
        $stagedReference = '.staging/'.$month.'/'.$filename;
        $stagedPath = $this->pathForReference($stagedReference);
        $this->ensureDirectory(\dirname($stagedPath));

        try {
            $file->move(\dirname($stagedPath), \basename($stagedPath));
            if (!is_file($stagedPath) || is_link($stagedPath)) {
                throw new \RuntimeException('Staged download file is unavailable.');
            }
        } catch (\Throwable $exception) {
            try {
                $this->discard([
                    'reference' => $reference,
                    'staged_reference' => $stagedReference,
                    'filename' => $filename,
                    'sha256' => $sha256,
                    'scan' => $scan,
                ]);
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    'Upload staging failed and cleanup could not be completed.',
                    0,
                    $cleanupFailure,
                );
            }

            throw $exception;
        }

        return [
            'reference' => $reference,
            'staged_reference' => $stagedReference,
            'filename' => $filename,
            'sha256' => $sha256,
            'scan' => $scan,
        ];
    }

    /**
     * @param array{
     *     reference: string,
     *     staged_reference: string,
     *     filename: string,
     *     sha256: string,
     *     scan: string
     * } $stored
     */
    public function finalize(array $stored): void
    {
        $source = $this->pathForReference($stored['staged_reference']);
        $target = $this->pathForReference($stored['reference']);
        $this->ensureDirectory(\dirname($target));

        if (!is_file($source) || is_link($source)) {
            throw new DownloadStorageUnavailable('Staged download file is unavailable.');
        }
        if (is_link($target) || file_exists($target)) {
            throw new \RuntimeException('Download storage target already exists.');
        }
        if (!rename($source, $target)) {
            throw new DownloadStorageUnavailable('Download file could not be finalized.');
        }
    }

    /**
     * @param array{
     *     reference: string,
     *     staged_reference: string,
     *     filename: string,
     *     sha256: string,
     *     scan: string
     * } $stored
     */
    public function discard(array $stored): void
    {
        $references = array_unique([
            $stored['staged_reference'],
            $stored['reference'],
        ]);

        foreach ($references as $reference) {
            $path = $this->pathForReference($reference);
            if (!is_file($path) && !is_link($path)) {
                continue;
            }
            if (is_link($path) || !is_file($path) || !unlink($path)) {
                throw new \RuntimeException('Download cleanup could not remove the stored file.');
            }
        }
    }

    public function rescan(string $reference): string
    {
        return $this->scanPath($this->absolutePath($reference));
    }

    public function absolutePath(string $reference): string
    {
        $root = realpath($this->storageRoot());
        if ($root === false) {
            throw new DownloadStorageUnavailable('Private download storage is unavailable.');
        }

        $path = realpath($root.'/'.str_replace('/', \DIRECTORY_SEPARATOR, $reference));
        if (
            $path === false
            || is_link($path)
            || !is_file($path)
            || !str_starts_with($path, $root.\DIRECTORY_SEPARATOR)
        ) {
            throw new DownloadStorageUnavailable('Download file is unavailable.');
        }

        return $path;
    }

    private function scanPath(string $path): string
    {
        $scannerStatus = $this->scanner->status();
        $this->scanner->scan($path);

        return $scannerStatus === 'ready' ? 'clean' : 'unavailable';
    }

    private function safeFilename(string $originalName): string
    {
        $extension = mb_strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::DANGEROUS_EXTENSIONS, true)) {
            throw new \DomainException('Executable or unsafe download type rejected.');
        }

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalName, \PATHINFO_FILENAME));
        $base = \is_string($base) ? trim($base, '-_.') : '';
        if ($base === '') {
            $base = 'download';
        }

        $filename = $base.'-'.bin2hex(random_bytes(8)).'.'.$extension;
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', $filename) !== 1) {
            throw new \DomainException('Safe filename could not be produced.');
        }

        return $filename;
    }

    private function storageRoot(): string
    {
        return rtrim($this->projectDir, \DIRECTORY_SEPARATOR)
            .\DIRECTORY_SEPARATOR.'var'
            .\DIRECTORY_SEPARATOR.'private-downloads';
    }

    private function pathForReference(string $reference): string
    {
        $this->assertReference($reference);

        return $this->storageRoot().\DIRECTORY_SEPARATOR
            .str_replace('/', \DIRECTORY_SEPARATOR, $reference);
    }

    private function assertReference(string $reference): void
    {
        if ($reference === '' || str_contains($reference, '\\') || str_contains($reference, '//')) {
            throw new \DomainException('Invalid private storage reference.');
        }

        $segments = explode('/', $reference);
        foreach ($segments as $index => $segment) {
            if ($index === 0 && $segment === '.staging') {
                continue;
            }
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $segment) !== 1
            ) {
                throw new \DomainException('Invalid private storage reference.');
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new DownloadStorageUnavailable('Private download directory is unsafe.');
        }
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DownloadStorageUnavailable('Private download directory is unavailable.');
        }
    }
}
