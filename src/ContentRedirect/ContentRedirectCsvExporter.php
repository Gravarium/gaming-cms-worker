<?php

declare(strict_types=1);

namespace App\ContentRedirect;

use App\Entity\ContentRedirect;

final class ContentRedirectCsvExporter
{
    /** @param iterable<ContentRedirect> $redirects */
    public function write(iterable $redirects, mixed $stream, bool $includeBom = false): int
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('A writable stream is required.');
        }

        if ($includeBom) {
            fwrite($stream, "\xEF\xBB\xBF");
        }

        if ($includeBom) {
            fputcsv($stream, ['Quellpfad', 'Inhaltstyp', 'Zieltitel', 'Zielslug', 'Status'], ',', '"', '', "\r\n");
        }
        $count = 0;
        foreach ($redirects as $redirect) {
            $entry = $redirect->getEntry();
            fputcsv($stream, [
                '/'.($redirect->getType() === 'news' ? 'news' : 'page').'/'.$redirect->getSourceSlug(),
                $redirect->getType(),
                $this->spreadsheetSafe($entry->getTitle()),
                $entry->getSlug(),
                $entry->isPublished() ? 'published' : 'unpublished',
            ], ',', '"', '', "\r\n");
            ++$count;
        }

        return $count;
    }

    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/\A[\x00-\x20]*[=+@-]/', $value) === 1 ? "'".$value : $value;
    }
}
