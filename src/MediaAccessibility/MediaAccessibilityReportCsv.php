<?php

declare(strict_types=1);

namespace App\MediaAccessibility;

use App\Entity\MediaAsset;

final class MediaAccessibilityReportCsv
{
    private const HEADERS = ['asset_id', 'mime_type', 'created_at_utc'];

    /** @param list<MediaAsset> $assets */
    public function encode(array $assets): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a temporary media accessibility CSV stream.');
        }

        try {
            $this->writeRow($stream, self::HEADERS);
            foreach ($assets as $asset) {
                $id = $asset->getId();
                if ($id === null) {
                    throw new \InvalidArgumentException('CSV export requires persisted media assets.');
                }
                $createdAt = $asset->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'));
                $this->writeRow($stream, [$id, $this->safeText((string) $asset->getMimeType()), $createdAt->format('Y-m-d\TH:i:s\Z')]);
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new \RuntimeException('Could not read the media accessibility CSV stream.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param list<int|string> $fields */
    private function writeRow($stream, array $fields): void
    {
        if (fputcsv($stream, $fields, ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Could not encode a media accessibility CSV row.');
        }
    }

    private function safeText(string $value): string
    {
        return preg_match('/\A[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
