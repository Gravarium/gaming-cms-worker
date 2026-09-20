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
        'js', 'mjs', 'html', 'htm', 'svg', 'shtml',
    ];

    public function __construct(private MediaMalwareScanner $malwareScanner)
    {
    }

    public function assertSafe(UploadedFile $file, string $moduleKey): void
    {
        if (!$file->isValid()) {
            throw new \DomainException('Der Upload ist unvollständig oder ungültig.');
        }

        $path = $file->getPathname();
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new \DomainException('Die hochgeladene Datei ist nicht sicher lesbar.');
        }

        $originalName = $file->getClientOriginalName();
        if ($originalName === '' || mb_strlen($originalName) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $originalName) === 1) {
            throw new \DomainException('Der ursprüngliche Dateiname ist ungültig.');
        }

        $segments = array_map('strtolower', explode('.', $originalName));
        foreach ($segments as $segment) {
            if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)) {
                throw new \DomainException('Der Dateiname enthält eine gefährliche Erweiterung.');
            }
        }

        $size = $file->getSize();
        if ($size === false || $size <= 0 || $size > $this->maxBytesFor($moduleKey)) {
            throw new \DomainException('Die Datei ist leer oder überschreitet die erlaubte Größe.');
        }

        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower($file->getClientOriginalExtension());
        if (!isset(self::MIME_EXTENSIONS[$mimeType]) || !in_array($extension, self::MIME_EXTENSIONS[$mimeType], true)) {
            throw new \DomainException('Dateiendung und tatsächlich erkannter Dateityp sind nicht erlaubt.');
        }

        $this->malwareScanner->scan($path);
    }

    private function maxBytesFor(string $moduleKey): int
    {
        return match (strtolower(trim($moduleKey))) {
            'video' => 500 * 1024 * 1024,
            'branding', 'gaming' => 5 * 1024 * 1024,
            default => 100 * 1024 * 1024,
        };
    }
}
