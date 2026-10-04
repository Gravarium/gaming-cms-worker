<?php

declare(strict_types=1);

namespace App\VideoProviderAudit;

final class CsvAudit
{
    private const HEADERS = ['source_id', 'provider', 'enabled', 'authorized', 'status', 'mode'];

    /**
     * @param list<array{source_id:int,provider:string,enabled:bool,authorized:bool,status:string,mode:?string}> $items
     */
    public function encode(array $items): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open a temporary stream for video audit CSV.');
        }

        try {
            $this->writeRow($stream, self::HEADERS);
            foreach ($items as $item) {
                $this->writeRow($stream, [
                    $item['source_id'],
                    $this->safeText($item['provider']),
                    $item['enabled'] ? 'true' : 'false',
                    $item['authorized'] ? 'true' : 'false',
                    $this->safeText($item['status']),
                    $item['mode'] === null ? '' : $this->safeText($item['mode']),
                ]);
            }

            if (!rewind($stream)) {
                throw new \RuntimeException('Could not rewind the temporary video audit CSV stream.');
            }

            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new \RuntimeException('Could not read the temporary video audit CSV stream.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     * @param list<int|string> $fields
     */
    private function writeRow($stream, array $fields): void
    {
        if (fputcsv($stream, $fields, ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Could not encode a video audit CSV row.');
        }
    }

    private function safeText(string $value): string
    {
        if (preg_match('/\A[\x00-\x20]*[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
