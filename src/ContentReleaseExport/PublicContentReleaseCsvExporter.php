<?php

declare(strict_types=1);

namespace App\ContentReleaseExport;

use App\Entity\ContentRelease;
use App\Repository\ContentReleaseRepository;

final class PublicContentReleaseCsvExporter
{
    private const PAGE_SIZE = 100;
    public const MAX_EXPORT_ROWS = 10_000;

    public function __construct(private readonly ContentReleaseRepository $releases)
    {
    }

    public function export(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $total = $this->releases->countPublicPublished($now);
        if ($total > self::MAX_EXPORT_ROWS) {
            throw new \LengthException('The public content release archive is too large for one CSV export.');
        }

        $stream = fopen('php://temp/maxmemory:5242880', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open the CSV export stream.');
        }

        try {
            $this->writeRow($stream, ['Release', 'Description', 'Published at']);

            for ($offset = 0; $offset < $total; $offset += self::PAGE_SIZE) {
                foreach ($this->releases->findPublicPublished(self::PAGE_SIZE, $offset, $now) as $release) {
                    $this->writeRow($stream, $this->rowFor($release));
                }
            }

            rewind($stream);
            $contents = stream_get_contents($stream);
            if (!is_string($contents)) {
                throw new \RuntimeException('Unable to read the CSV export stream.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    public static function spreadsheetSafeCell(string $value): string
    {
        if (preg_match('/\A(?:\s*[=+\-@]|[\t\r])/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function rowFor(ContentRelease $release): array
    {
        return [
            self::spreadsheetSafeCell($release->getName()),
            self::spreadsheetSafeCell($release->getDescription() ?? ''),
            $release->getPublishedAt()?->format(DATE_ATOM) ?? '',
        ];
    }

    /**
     * @param resource $stream
     * @param list<string> $row
     */
    private function writeRow($stream, array $row): void
    {
        $result = fputcsv($stream, $row, ',', '"', '', "\r\n");
        if ($result === false) {
            throw new \RuntimeException('Unable to write a CSV export row.');
        }
    }
}
