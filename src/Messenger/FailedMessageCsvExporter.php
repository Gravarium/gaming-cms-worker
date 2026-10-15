<?php

declare(strict_types=1);

namespace App\Messenger;

final readonly class FailedMessageCsvExporter
{
    /**
     * @param list<array{id: string, type: string, createdAt: \DateTimeImmutable, bytes: int}> $messages
     */
    public function export(array $messages): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not open the CSV stream.');
        }

        $rows = [[
            'Message ID',
            'Nachrichtentyp',
            'Fehlgeschlagen seit (UTC)',
            'Nutzlastgröße (Bytes)',
        ]];
        foreach ($messages as $message) {
            $rows[] = [
                $message['id'],
                $message['type'],
                $message['createdAt']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                (string) max(0, $message['bytes']),
            ];
        }

        foreach ($rows as $row) {
            $safeRow = array_map(self::safeCell(...), $row);
            if (fputcsv($stream, $safeRow, ',', '"', '', "\r\n") === false) {
                fclose($stream);
                throw new \RuntimeException('Could not write the CSV row.');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \RuntimeException('Could not read the generated CSV.');
        }

        return $csv;
    }

    private static function safeCell(string $value): string
    {
        return preg_match('/\A[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
