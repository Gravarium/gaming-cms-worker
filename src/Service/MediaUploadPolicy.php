<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class MediaUploadPolicy
{
    private const MIME_EXTENSIONS = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/x-icon' => ['ico'],
        'image/vnd.microsoft.icon' => ['ico'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/quicktime' => ['mov'],
        'audio/mpeg' => ['mp3'],
        'audio/ogg' => ['ogg'],
        'audio/wav' => ['wav'],
        'audio/x-wav' => ['wav'],
        'application/pdf' => ['pdf'],
        'application/zip' => ['zip'],
        'application/x-zip-compressed' => ['zip'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'application/json' => ['json'],
    ];

    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
        'js', 'mjs', 'html', 'htm', 'svg', 'shtml', 'xhtml', 'xml', 'xsl', 'xslt',
    ];

    public function __construct(private MediaMalwareScanner $malwareScanner)
    {
    }

    public function assertSafe(UploadedFile $file, string $moduleKey): void
    {
        $this->assertModuleKey($moduleKey);

        if (!$file->isValid()) {
            throw new \DomainException('Der Upload ist unvollständig oder ungültig.');
        }

        $path = $file->getPathname();
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new \DomainException('Die hochgeladene Datei ist nicht sicher lesbar.');
        }

        $originalName = $file->getClientOriginalName();
        $this->assertOriginalName($originalName);

        $size = $file->getSize();
        if ($size === false || $size <= 0 || $size > $this->maxBytesFor($moduleKey)) {
            throw new \DomainException('Die Datei ist leer oder überschreitet die erlaubte Größe.');
        }

        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower($file->getClientOriginalExtension());
        if (!isset(self::MIME_EXTENSIONS[$mimeType]) || !in_array($extension, self::MIME_EXTENSIONS[$mimeType], true)) {
            throw new \DomainException('Dateiendung und tatsächlich erkannter Dateityp sind nicht erlaubt.');
        }

        $this->assertBasicIntegrity($path, $mimeType);
        $this->malwareScanner->scan($path);
    }

    public function maxBytesFor(string $moduleKey): int
    {
        $this->assertModuleKey($moduleKey);

        return match (strtolower(trim($moduleKey))) {
            'video' => 500 * 1024 * 1024,
            'branding', 'gaming' => 5 * 1024 * 1024,
            default => 100 * 1024 * 1024,
        };
    }

    private function assertModuleKey(string $moduleKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $moduleKey) !== 1) {
            throw new \DomainException('Das Upload-Zielmodul ist ungültig.');
        }
    }

    private function assertOriginalName(string $originalName): void
    {
        if ($originalName === ''
            || mb_strlen($originalName) > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $originalName) === 1
            || str_contains($originalName, '/')
            || str_contains($originalName, '\\')
            || str_contains($originalName, '..')
            || trim($originalName, " .\t\n\r\0\x0B") !== $originalName
        ) {
            throw new \DomainException('Der ursprüngliche Dateiname ist ungültig.');
        }

        $segments = array_map('strtolower', explode('.', $originalName));
        foreach ($segments as $segment) {
            if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)) {
                throw new \DomainException('Der Dateiname enthält eine gefährliche Erweiterung.');
            }
        }

        $baseName = strtoupper((string) pathinfo($originalName, PATHINFO_FILENAME));
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $baseName) === 1) {
            throw new \DomainException('Der Dateiname ist auf unterstützten Dateisystemen reserviert.');
        }
    }

    private function assertBasicIntegrity(string $path, string $mimeType): void
    {
        if (in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)
            && @getimagesize($path) === false
        ) {
            throw new \DomainException('Die Bilddatei ist beschädigt oder unvollständig.');
        }

        $prefix = file_get_contents($path, false, null, 0, 16);
        if ($prefix === false) {
            throw new \DomainException('Die Datei konnte nicht geprüft werden.');
        }

        if ($mimeType === 'application/pdf' && !str_starts_with($prefix, '%PDF-')) {
            throw new \DomainException('Die PDF-Datei ist beschädigt oder unvollständig.');
        }

        if (in_array($mimeType, ['application/zip', 'application/x-zip-compressed'], true)
            && !str_starts_with($prefix, "PK\x03\x04")
            && !str_starts_with($prefix, "PK\x05\x06")
            && !str_starts_with($prefix, "PK\x07\x08")
        ) {
            throw new \DomainException('Die ZIP-Datei ist beschädigt oder unvollständig.');
        }

        if ($mimeType === 'application/json') {
            try {
                json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \DomainException('Die JSON-Datei ist beschädigt oder ungültig.');
            }
        }

        if ($mimeType === 'video/mp4' && substr($prefix, 4, 4) !== 'ftyp') {
            throw new \DomainException('Die MP4-Datei ist beschädigt oder unvollständig.');
        }

        if ($mimeType === 'video/webm' && !str_starts_with($prefix, "\x1A\x45\xDF\xA3")) {
            throw new \DomainException('Die WebM-Datei ist beschädigt oder unvollständig.');
        }
    }
}
