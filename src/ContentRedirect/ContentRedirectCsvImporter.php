<?php

declare(strict_types=1);

namespace App\ContentRedirect;

use InvalidArgumentException;

final class ContentRedirectCsvImporter
{
    private const HEADER = ['Quellpfad', 'Inhaltstyp', 'Zieltitel', 'Zielslug', 'Status'];
    private const SOURCE_PATTERN = '/\A\/(news|page)\/([a-z0-9]+(?:-[a-z0-9]+)*)\z/';

    /**
     * @param resource $stream
     * @return list<array{type: string, source_slug: string, target_slug: string, status: string}>
     */
    public function parse(mixed $stream, int $maxRows = 5000): array
    {
        if (!is_resource($stream) || $maxRows < 1 || $maxRows > 5000) {
            throw new InvalidArgumentException('Ein lesbarer Stream und ein Zeilenlimit von 1 bis 5000 sind erforderlich.');
        }

        $header = fgetcsv($stream, null, ',', '"', '');
        if (!is_array($header) || count($header) !== count(self::HEADER)) {
            throw new InvalidArgumentException('Die CSV-Datei enthält keine Kopfzeile.');
        }
        $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', (string) $header[0]);
        if ($header !== self::HEADER) {
            throw new InvalidArgumentException('Die CSV-Kopfzeile entspricht nicht dem Redirect-Export.');
        }

        $rows = [];
        $line = 1;
        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            ++$line;
            if ($fields === [null] || $fields === ['']) {
                continue;
            }
            if (count($fields) !== 5) {
                throw new InvalidArgumentException(sprintf('CSV-Zeile %d hat nicht genau fünf Felder.', $line));
            }
            if (!is_string($fields[0]) || !is_string($fields[1]) || !is_string($fields[2]) || !is_string($fields[3]) || !is_string($fields[4])) {
                throw new InvalidArgumentException(sprintf('CSV-Zeile %d enthält ein fehlendes Feld.', $line));
            }
            foreach ($fields as $field) {
                if (!mb_check_encoding($field, 'UTF-8')) {
                    throw new InvalidArgumentException(sprintf('CSV-Zeile %d enthält ungültiges UTF-8.', $line));
                }
            }

            [$sourcePath, $type, , $targetSlug, $status] = $fields;
            if (preg_match(self::SOURCE_PATTERN, $sourcePath, $matches) !== 1
                || $type !== $matches[1]
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $targetSlug) !== 1
                || !in_array($status, ['published', 'unpublished'], true)
            ) {
                throw new InvalidArgumentException(sprintf('CSV-Zeile %d enthält einen ungültigen Redirect.', $line));
            }
            if (count($rows) >= $maxRows) {
                throw new InvalidArgumentException(sprintf('Die CSV-Datei überschreitet das Zeilenlimit von %d.', $maxRows));
            }

            $rows[] = ['type' => $type, 'source_slug' => $matches[2], 'target_slug' => $targetSlug, 'status' => $status];
        }

        return $rows;
    }
}
