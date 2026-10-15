<?php

declare(strict_types=1);

namespace App\GameCatalogueExport;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;

final class PublicGameCatalogueCsvExporter
{
    public const MAX_EXPORT_ROWS = GameCatalogueEntryRepository::PAGE_SIZE * GameCatalogueEntryRepository::MAX_PAGE;

    public function __construct(private readonly GameCatalogueEntryRepository $entries)
    {
    }

    public function export(): string
    {
        $total = $this->entries->countPublicEntries();
        if ($total > self::MAX_EXPORT_ROWS) {
            throw new \LengthException('The public game catalogue is too large for one CSV export.');
        }

        $stream = fopen('php://temp/maxmemory:5242880', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open the CSV export stream.');
        }

        try {
            $this->writeRow($stream, ['Game', 'Summary', 'Developer', 'Genres']);

            $totalPages = (int) ceil($total / GameCatalogueEntryRepository::PAGE_SIZE);
            for ($page = 1; $page <= $totalPages; ++$page) {
                foreach ($this->entries->publicEntries(null, null, $page) as $entry) {
                    $this->writeRow($stream, $this->rowFor($entry));
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
    private function rowFor(GameCatalogueEntry $entry): array
    {
        $genres = array_map(
            static fn (GameGenre $genre): string => self::spreadsheetSafeCell($genre->getName()),
            $entry->getGenres()->toArray(),
        );
        sort($genres, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            self::spreadsheetSafeCell($entry->getGame()->getName()),
            self::spreadsheetSafeCell($entry->getSummary() ?? ''),
            self::spreadsheetSafeCell($entry->getDeveloper() ?? ''),
            implode('; ', $genres),
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
