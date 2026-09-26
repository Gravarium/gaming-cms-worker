<?php

declare(strict_types=1);

namespace App\Messenger;

use Doctrine\DBAL\Connection;

final readonly class FailedMessageOverview
{
    public const MAX_HEADER_BYTES = 8192;
    private const JSON_DEPTH = 16;

    public function __construct(private Connection $connection)
    {
    }

    /** @return array{total: int, messages: list<array{id: string, type: string, createdAt: \DateTimeImmutable, bytes: int}>} */
    public function read(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            ['failed'],
        );
        $headerProjectionLength = self::MAX_HEADER_BYTES + 1;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, SUBSTR(headers, 1, '.$headerProjectionLength.') AS headers, created_at, LENGTH(body) AS body_size FROM messenger_messages WHERE queue_name = ? ORDER BY id DESC LIMIT '.$limit,
            ['failed'],
        );

        $messages = [];
        foreach ($rows as $row) {
            $createdAt = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));
            $messages[] = [
                'id' => (string) $row['id'],
                'type' => self::sanitizedType((string) $row['headers']),
                'createdAt' => $createdAt,
                'bytes' => max(0, (int) $row['body_size']),
            ];
        }

        return ['total' => $total, 'messages' => $messages];
    }

    public static function sanitizedType(string $headers): string
    {
        if (strlen($headers) > self::MAX_HEADER_BYTES) {
            return 'Unbekannte Nachricht';
        }

        try {
            $decoded = json_decode($headers, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'Unbekannte Nachricht';
        }

        $type = is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : '';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,240}$/', $type) !== 1) {
            return 'Unbekannte Nachricht';
        }

        $parts = explode('\\', $type);

        return (string) end($parts);
    }
}
